<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TenantChanged;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\DatosInstitucionales;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Jornada;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\ModeloPedagogico;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;

/**
 * Gate de operabilidad del colegio: `configuring → active` (D-CONFIG-MIN, RN-CC-001).
 *
 * El tenant nace en `configuring` tras el provisioning. La configuracion minima
 * exige COMPLETOS los bloques 1-6 (institucional, calendario, jornadas, escala,
 * metodo de aprobacion, modelo pedagogico). Los bloques 7-9 (pagos, matricula,
 * publicaciones) no bloquean operar la academia.
 *
 * Uso (dentro del contexto del colegio, tras una escritura de configuracion):
 *   ConfigurationGate::maybeActivate($request->user());
 *
 * Ejecutar SIEMPRE en contexto de tenant (subdominio o X-Tenant).
 */
final class ConfigurationGate
{
    /** Bloques que componen la configuracion minima (D-CONFIG-MIN). */
    public const BLOCKS = [
        'institucional' => 'Bloque 1 · Datos institucionales',
        'calendario' => 'Bloque 2 · Calendario escolar',
        'jornadas' => 'Bloque 3 · Jornadas y bloques',
        'escala' => 'Bloque 4 · Escala valorativa',
        'metodo' => 'Bloque 5 · Metodo de aprobacion',
        'modelo' => 'Bloque 6 · Modelo pedagogico',
    ];

    /**
     * Estado de cada bloque (completo/incompleto) en el colegio activo.
     *
     * @return array{bloques: array<string, array{nombre: string, completo: bool}>, completo: bool, tenant_estado: string}
     */
    public static function estado(): array
    {
        $ano = AnoLectivo::query()
            ->orderByRaw('CASE WHEN estado = ? THEN 0 ELSE 1 END', [AnoLectivo::ESTADO_EN_CURSO])
            ->orderByDesc('fecha_inicio')
            ->first();

        $checks = [
            'institucional' => fn (): bool => DatosInstitucionales::query()
                ->whereNotNull('nombre')
                ->where('nombre', '!=', '')
                ->exists(),
            'calendario' => fn (): bool => $ano !== null && self::hasCompleteCalendar($ano),
            'jornadas' => fn (): bool => Jornada::query()
                ->where('estado', Jornada::ESTADO_ACTIVA)
                ->whereHas('bloques', fn ($query) => $query->where('estado', BloqueHorario::ESTADO_ACTIVO))
                ->exists(),
            'escala' => fn (): bool => $ano !== null && EscalaValorativa::query()->where('ano_lectivo_id', $ano->id)->exists(),
            'metodo' => fn (): bool => $ano !== null && MetodoAprobacion::query()->where('ano_lectivo_id', $ano->id)->exists(),
            'modelo' => fn (): bool => $ano !== null && ModeloPedagogico::query()->where('ano_lectivo_id', $ano->id)->exists(),
        ];

        $bloques = [];

        foreach (self::BLOCKS as $key => $nombre) {
            $bloques[$key] = [
                'nombre' => $nombre,
                'completo' => (bool) $checks[$key](),
            ];
        }

        $completo = self::areBlocksComplete(array_map(
            static fn (array $bloque): bool => $bloque['completo'],
            $bloques,
        ));

        return [
            'bloques' => $bloques,
            'completo' => $completo,
            'tenant_estado' => (string) (tenant()?->status ?? Tenant::STATUS_CONFIGURING),
        ];
    }

    /**
     * ¿La configuracion minima esta completa? (requiere contexto de tenant).
     */
    public static function isComplete(): bool
    {
        return self::estado()['completo'];
    }

    /** @param array<string, bool> $checks */
    public static function areBlocksComplete(array $checks): bool
    {
        return array_keys($checks) === array_keys(self::BLOCKS)
            && ! in_array(false, $checks, true);
    }

    /** Comprueba cantidad, orden, continuidad y cobertura total del año. */
    public static function hasCompleteCalendar(AnoLectivo $ano): bool
    {
        $periodos = $ano->periodos()->orderBy('orden')->get();
        $esperados = $ano->num_periodos + ($ano->tiene_quinto_periodo ? 1 : 0);

        if ($periodos->count() !== $esperados) {
            return false;
        }

        foreach ($periodos->values() as $index => $periodo) {
            if ($periodo->orden !== $index + 1 || $periodo->fecha_inicio->gt($periodo->fecha_fin)) {
                return false;
            }

            if ($index === 0 && ! $periodo->fecha_inicio->equalTo($ano->fecha_inicio)) {
                return false;
            }

            if ($index > 0 && ! $periodo->fecha_inicio->equalTo($periodos[$index - 1]->fecha_fin->copy()->addDay())) {
                return false;
            }
        }

        return $periodos->last()?->fecha_fin->equalTo($ano->fecha_fin) ?? false;
    }

    /**
     * Si el colegio esta `configuring` y la configuracion minima esta completa,
     * lo promueve a `active` con doble auditoria (plataforma + colegio).
     *
     * @param  object|null  $actor  Usuario que disparo el ultimo bloque.
     */
    public static function maybeActivate(?object $actor): ?Tenant
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || $tenant->status !== Tenant::STATUS_CONFIGURING) {
            return null;
        }

        if (! self::isComplete()) {
            return null;
        }

        $prev = ['status' => $tenant->status];
        $tenant->update(['status' => Tenant::STATUS_ACTIVE]);

        AuditLogger::tenant(
            $actor,
            'UPDATE',
            'tenant.config',
            (string) $tenant->id,
            $prev,
            ['status' => $tenant->status],
            'Configuracion minima completa (D-CONFIG-MIN): bloques 1-6. Colegio activo.',
        );

        AuditLogger::platform(
            $actor,
            'UPDATE',
            'colegio',
            (string) $tenant->id,
            $prev,
            ['status' => $tenant->status],
            'Configuracion minima completa (D-CONFIG-MIN): bloques 1-6. Colegio activo.',
            (string) $tenant->id,
        );

        TenantChanged::dispatch($tenant->id, 'activated');

        if ($tenant->tipo === Tenant::TIPO_SEDE && $tenant->parent_id !== null) {
            $parent = $tenant->parent()->first();
            if ($parent !== null) {
                tenancy()->end();
                try {
                    $parent->run(fn () => Sede::query()
                        ->where('tenant_id', $tenant->id)
                        ->update(['estado' => Sede::ESTADO_ACTIVA]));
                } finally {
                    tenancy()->initialize($tenant);
                }
            }
        }

        return $tenant;
    }
}
