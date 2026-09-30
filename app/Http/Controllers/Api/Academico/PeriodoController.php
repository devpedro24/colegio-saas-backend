<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Periodo;
use App\Services\PeriodoLifecycleService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestión de periodos académicos de un año lectivo (periodos-academicos.md).
 * Rutas del tenant. `index` y `store` van anidadas bajo el año lectivo; las
 * mutaciones sobre un periodo concreto (update/abrir/cerrar/destroy) se
 * resuelven por el id del periodo, alineadas con el frontend.
 *
 * El backend es la AUTORIDAD de la FSM del periodo:
 *   planificado → abierto → cerrado → abierto (reapertura excepcional).
 * Los periodos quedan dentro del rango del año y no se solapan.
 */
class PeriodoController extends Controller
{
    /** Lista los periodos de un año lectivo, en orden. */
    public function index(string $anoLectivoId): JsonResponse
    {
        $ano = AnoLectivo::findOrFail($anoLectivoId);
        app(PeriodoLifecycleService::class)->synchronize($ano);

        $periodos = $ano->periodos()
            ->get()
            ->map(fn (Periodo $periodo) => $this->present($periodo));

        return response()->json(['data' => $periodos]);
    }

    /** Crea un periodo dentro de un año lectivo (nace 'planificado'). */
    public function store(Request $request, string $anoLectivoId): JsonResponse
    {
        $ano = AnoLectivo::findOrFail($anoLectivoId);

        if ($ano->estaCerrado()) {
            abort(422, 'El año lectivo está cerrado; no se pueden agregar periodos.');
        }

        $data = $this->validated($request);
        $data['nombre'] = $data['nombre'] ?? $this->nombreSegunOrden((int) $data['orden']);

        // El orden es único dentro del año lectivo.
        $ordenOcupado = $ano->periodos()->where('orden', $data['orden'])->exists();
        if ($ordenOcupado) {
            abort(422, 'Ya existe un periodo con ese orden en el año lectivo.');
        }

        $this->validarOrdenDentroDelLimite($ano, (int) $data['orden']);
        $this->validarFechasSinSolapamiento($ano, $data);

        $periodo = $ano->periodos()->create([
            'nombre' => $data['nombre'],
            'orden' => $data['orden'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'peso' => $this->resolverPeso($ano, $data['peso'] ?? null),
            'estado' => Periodo::ESTADO_PLANIFICADO,
        ]);

        AuditLogger::tenant(
            $request->user(),
            'CREATE',
            'periodo',
            (string) $periodo->id,
            null,
            $this->snapshot($periodo),
        );

        try {
            TenantDataChanged::dispatch('periodo', 'created', $data['nombre']);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->present($periodo)], 201);
    }

    /** Edita un periodo (bloqueado si está cerrado o el año está cerrado — RN-PA-006). */
    public function update(Request $request, string $id): JsonResponse
    {
        $periodo = Periodo::findOrFail($id);
        $ano = $periodo->anoLectivo()->firstOrFail();

        if ($ano->estaCerrado()) {
            abort(422, 'El año lectivo está cerrado y sus datos son inmutables.');
        }

        // RN-PA-006: un periodo cerrado es inmutable.
        if ($periodo->estaCerrado()) {
            abort(422, 'El período está cerrado. Solo el rector puede reabrirlo antes de modificarlo.');
        }

        $data = $this->validated($request);
        $data['nombre'] = $data['nombre'] ?? $this->nombreSegunOrden((int) $data['orden']);

        // El orden es único dentro del año lectivo (excluyendo el propio periodo).
        $ordenOcupado = $ano->periodos()
            ->where('orden', $data['orden'])
            ->where('id', '!=', $periodo->id)
            ->exists();
        if ($ordenOcupado) {
            abort(422, 'Ya existe otro periodo con ese orden en el año lectivo.');
        }

        $this->validarOrdenDentroDelLimite($ano, (int) $data['orden']);
        $this->validarFechasSinSolapamiento($ano, $data, $periodo->id);

        $prev = $this->snapshot($periodo);

        $periodo->update([
            'nombre' => $data['nombre'],
            'orden' => $data['orden'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'peso' => $this->resolverPeso($ano, $data['peso'] ?? null),
        ]);

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'periodo',
            (string) $periodo->id,
            $prev,
            $this->snapshot($periodo),
        );

        try {
            TenantDataChanged::dispatch('periodo', 'updated', $periodo->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->present($periodo)]);
    }

    /** Elimina físicamente un período vacío; el año cerrado permanece inmutable. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $periodo = Periodo::findOrFail($id);
        $ano = $periodo->anoLectivo()->firstOrFail();

        if ($ano->estaCerrado()) {
            abort(422, 'El año lectivo está cerrado y sus datos son inmutables.');
        }

        if ($periodo->estaCerrado()) {
            abort(422, 'El período está cerrado. Solo el rector puede reabrirlo antes de modificarlo.');
        }

        if ($this->tieneInformacionAnclada($periodo)) {
            abort(422, 'No se puede eliminar el periodo porque tiene notas, informes u otra información asociada.');
        }

        $prev = $this->snapshot($periodo);
        // Un período sin datos asociados es solo configuración: se borra físicamente.
        $periodo->forceDelete();

        AuditLogger::tenant(
            $request->user(),
            'DELETE',
            'periodo',
            (string) $periodo->id,
            $prev,
            null,
        );

        try {
            TenantDataChanged::dispatch('periodo', 'deleted', $periodo->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => null]);
    }

    /** Transición: planificado → abierto. Permite registrar notas/asistencia/logros. */
    public function abrir(Request $request, string $id): JsonResponse
    {
        $this->assertTransitionActor($request);
        $ano = Periodo::findOrFail($id)->anoLectivo()->firstOrFail();
        app(PeriodoLifecycleService::class)->synchronize($ano);
        [$periodo, $prev] = DB::transaction(function () use ($id): array {
            $periodo = Periodo::query()->lockForUpdate()->findOrFail($id);
            if ($periodo->anoLectivo()->firstOrFail()->estado !== AnoLectivo::ESTADO_EN_CURSO) {
                abort(422, 'Solo se pueden abrir períodos de un año lectivo en curso.');
            }
            if ($periodo->estado !== Periodo::ESTADO_PLANIFICADO) {
                abort(422, 'Solo un período planificado puede abrirse.');
            }
            $prev = $this->snapshot($periodo);
            $periodo->update(['estado' => Periodo::ESTADO_ABIERTO, 'reapertura_manual' => false]);

            return [$periodo, $prev];
        });

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'periodo',
            (string) $periodo->id,
            $prev,
            $this->snapshot($periodo),
            'Apertura del periodo (planificado → abierto).',
        );

        try {
            TenantDataChanged::dispatch('periodo', 'updated', $periodo->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->present($periodo)]);
    }

    /** Transición: abierto → cerrado (RN-PA-006, datos inmutables tras el cierre). */
    public function cerrar(Request $request, string $id): JsonResponse
    {
        $this->assertTransitionActor($request);
        [$periodo, $prev] = DB::transaction(function () use ($id): array {
            $periodo = Periodo::query()->lockForUpdate()->findOrFail($id);
            if ($periodo->anoLectivo()->firstOrFail()->estado !== AnoLectivo::ESTADO_EN_CURSO) {
                abort(422, 'El año lectivo debe estar en curso para cerrar un período.');
            }
            if ($periodo->estado !== Periodo::ESTADO_ABIERTO) {
                abort(422, 'Solo un período abierto puede cerrarse.');
            }
            $prev = $this->snapshot($periodo);
            $periodo->update(['estado' => Periodo::ESTADO_CERRADO, 'reapertura_manual' => false]);

            return [$periodo, $prev];
        });

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'periodo',
            (string) $periodo->id,
            $prev,
            $this->snapshot($periodo),
            'Cierre del periodo (abierto → cerrado).',
        );

        try {
            TenantDataChanged::dispatch('periodo', 'updated', $periodo->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->present($periodo)]);
    }

    /** Reapertura excepcional: cerrado → abierto, aunque ya terminó su fecha. */
    public function reabrir(Request $request, string $id): JsonResponse
    {
        $this->assertTransitionActor($request);
        [$periodo, $prev] = DB::transaction(function () use ($id): array {
            $periodo = Periodo::query()->lockForUpdate()->findOrFail($id);
            if ($periodo->anoLectivo()->firstOrFail()->estado !== AnoLectivo::ESTADO_EN_CURSO) {
                abort(422, 'El año lectivo debe estar en curso para reabrir un período.');
            }
            if ($periodo->estado !== Periodo::ESTADO_CERRADO) {
                abort(422, 'Solo un período cerrado puede reabrirse.');
            }
            $prev = $this->snapshot($periodo);
            $periodo->update(['estado' => Periodo::ESTADO_ABIERTO, 'reapertura_manual' => true]);

            return [$periodo, $prev];
        });

        AuditLogger::tenant(
            $request->user(), 'UPDATE', 'periodo', (string) $periodo->id,
            $prev, $this->snapshot($periodo),
            'Reapertura manual excepcional; permanece abierto hasta el cierre del rector.',
        );
        try {
            TenantDataChanged::dispatch('periodo', 'updated', $periodo->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->present($periodo)]);
    }

    private function assertTransitionActor(Request $request): void
    {
        $actor = $request->user();
        abort_unless(
            $actor && $actor->can('academico.periodos.transicionar')
                && ($actor->hasRole('rector') || $actor->esSuperadminPlataforma()),
            403,
            'Solo el rector o el superadministrador dentro del colegio puede abrir o cerrar períodos.',
        );
    }

    /**
     * Reglas de validación compartidas por store/update.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre' => ['nullable', 'string', 'max:120'],
            'orden' => ['required', 'integer', 'min:1', 'max:12'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'peso' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
    }

    /**
     * El orden del periodo no debe exceder el número de periodos del año
     * El resultado sumatorio no es un período real y no consume un orden.
     */
    private function validarOrdenDentroDelLimite(AnoLectivo $ano, int $orden): void
    {
        $maximo = $ano->num_periodos;

        if ($orden > $maximo) {
            abort(422, "El orden del periodo excede el número de periodos del año lectivo (máximo {$maximo}).");
        }
    }

    private function nombreSegunOrden(int $orden): string
    {
        return match ($orden) {
            1 => 'Primer período',
            2 => 'Segundo período',
            3 => 'Tercer período',
            4 => 'Cuarto período',
            5 => 'Quinto período',
            default => 'Período '.$orden,
        };
    }

    private function resolverPeso(AnoLectivo $ano, mixed $peso): ?float
    {
        return $peso === null ? null : (float) $peso;
    }

    /**
     * Valida el rango del año y evita solapamientos. Mientras se configura se
     * permiten huecos; al iniciar el año se exige cobertura completa.
     *
     * @param  array<string, mixed>  $data
     */
    private function validarFechasSinSolapamiento(AnoLectivo $ano, array $data, ?int $ignorarId = null): void
    {
        $inicio = Carbon::parse($data['fecha_inicio'])->startOfDay();
        $fin = Carbon::parse($data['fecha_fin'])->startOfDay();

        // Dentro del rango del año lectivo.
        if ($inicio->lt($ano->fecha_inicio->copy()->startOfDay()) || $fin->gt($ano->fecha_fin->copy()->startOfDay())) {
            abort(422, 'Las fechas del periodo deben estar dentro del rango del año lectivo.');
        }

        // Ningún período puede cruzarse con otro del mismo año lectivo.
        $periodos = $ano->periodos()
            ->when($ignorarId !== null, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->get(['id', 'orden', 'fecha_inicio', 'fecha_fin'])
            ->map(fn (Periodo $p) => [
                'orden' => $p->orden,
                'fecha_inicio' => $p->fecha_inicio->copy()->startOfDay(),
                'fecha_fin' => $p->fecha_fin->copy()->startOfDay(),
            ]);

        foreach ($periodos as $p) {
            if ($inicio->lte($p['fecha_fin']) && $fin->gte($p['fecha_inicio'])) {
                abort(422, 'Las fechas del periodo se cruzan con otro periodo ya configurado.');
            }
        }
    }

    /**
     * Busca datos vinculados en cualquier módulo del colegio antes de hacer un
     * borrado físico. Los módulos que anclen información al período usan
     * periodo_id o period_id, por ejemplo notas e informes.
     */
    private function tieneInformacionAnclada(Periodo $periodo): bool
    {
        try {
            foreach (Schema::getTables() as $tabla) {
                $nombre = is_array($tabla) ? $tabla['name'] : $tabla->name;
                if ($nombre === $periodo->getTable()) {
                    continue;
                }

                $columnas = Schema::getColumnListing($nombre);
                foreach (['periodo_id', 'period_id'] as $columna) {
                    if (in_array($columna, $columnas, true)
                        && DB::table($nombre)->where($columna, $periodo->getKey())->exists()) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            // Ante un problema de inspección se protege la información existente.
            abort(422, 'No se pudo comprobar si el periodo tiene información asociada; no se eliminó.');
        }

        return false;
    }

    /**
     * Instantánea de auditoría del periodo.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Periodo $periodo): array
    {
        return [
            'ano_lectivo_id' => $periodo->ano_lectivo_id,
            'nombre' => $periodo->nombre,
            'orden' => $periodo->orden,
            'fecha_inicio' => $periodo->fecha_inicio?->toDateString(),
            'fecha_fin' => $periodo->fecha_fin?->toDateString(),
            'peso' => $periodo->peso,
            'estado' => $periodo->estado,
            'reapertura_manual' => $periodo->reapertura_manual,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Periodo $periodo): array
    {
        $today = PeriodoLifecycleService::today();

        return [
            'id' => $periodo->id,
            'ano_lectivo_id' => $periodo->ano_lectivo_id,
            'nombre' => $periodo->nombre,
            'orden' => $periodo->orden,
            'fecha_inicio' => $periodo->fecha_inicio?->toDateString(),
            'fecha_fin' => $periodo->fecha_fin?->toDateString(),
            'peso' => $periodo->peso,
            'estado' => $periodo->estado,
            'reapertura_manual' => $periodo->reapertura_manual,
            'es_actual' => $periodo->estado === Periodo::ESTADO_ABIERTO
                && $periodo->fecha_inicio->toDateString() <= $today
                && $periodo->fecha_fin->toDateString() >= $today,
            'created_at' => $periodo->created_at?->toIso8601String(),
        ];
    }
}
