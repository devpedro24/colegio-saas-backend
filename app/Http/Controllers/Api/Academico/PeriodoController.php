<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Periodo;
use App\Services\ConfigurationGate;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Gestión de periodos académicos de un año lectivo (periodos-academicos.md).
 * Rutas del tenant. `index` y `store` van anidadas bajo el año lectivo; las
 * mutaciones sobre un periodo concreto (update/abrir/cerrar/destroy) se
 * resuelven por el id del periodo, alineadas con el frontend.
 *
 * El backend es la AUTORIDAD de la FSM del periodo:
 *   planificado → abierto → cerrado.
 * Los periodos son contiguos y quedan dentro del rango del año (RN-PA-003).
 */
class PeriodoController extends Controller
{
    /** Lista los periodos de un año lectivo, en orden. */
    public function index(string $anoLectivoId): JsonResponse
    {
        $ano = AnoLectivo::findOrFail($anoLectivoId);

        $periodos = $ano->periodos()
            ->get()
            ->map(fn (Periodo $periodo) => $this->present($periodo));

        return response()->json(['data' => $periodos]);
    }

    /** Crea un periodo dentro de un año lectivo (nace 'planificado'). */
    public function store(Request $request, string $anoLectivoId): JsonResponse
    {
        $ano = AnoLectivo::findOrFail($anoLectivoId);

        if ($ano->estado !== AnoLectivo::ESTADO_PLANIFICADO) {
            abort(422, 'Solo se pueden agregar periodos mientras el año lectivo está planificado.');
        }

        $data = $this->validated($request);

        // El orden es único dentro del año lectivo.
        $ordenOcupado = $ano->periodos()->where('orden', $data['orden'])->exists();
        if ($ordenOcupado) {
            abort(422, 'Ya existe un periodo con ese orden en el año lectivo.');
        }

        $this->validarOrdenDentroDelLimite($ano, (int) $data['orden']);
        $this->validarFechasYContiguidad($ano, $data);

        $periodo = $ano->periodos()->create([
            'nombre' => $data['nombre'],
            'orden' => $data['orden'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'peso' => $data['peso'] ?? null,
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
        ConfigurationGate::maybeActivate($request->user());

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
            abort(422, 'El periodo está cerrado y sus datos son inmutables.');
        }

        $data = $this->validated($request);

        // El orden es único dentro del año lectivo (excluyendo el propio periodo).
        $ordenOcupado = $ano->periodos()
            ->where('orden', $data['orden'])
            ->where('id', '!=', $periodo->id)
            ->exists();
        if ($ordenOcupado) {
            abort(422, 'Ya existe otro periodo con ese orden en el año lectivo.');
        }

        $this->validarOrdenDentroDelLimite($ano, (int) $data['orden']);
        $this->validarFechasYContiguidad($ano, $data, $periodo->id);

        $prev = $this->snapshot($periodo);

        $periodo->update([
            'nombre' => $data['nombre'],
            'orden' => $data['orden'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'peso' => $data['peso'] ?? null,
        ]);

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'periodo',
            (string) $periodo->id,
            $prev,
            $this->snapshot($periodo),
        );
        ConfigurationGate::maybeActivate($request->user());

        try {
            TenantDataChanged::dispatch('periodo', 'updated', $periodo->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->present($periodo)]);
    }

    /** Elimina un periodo (bloqueado si está cerrado o el año está cerrado). */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $periodo = Periodo::findOrFail($id);
        $ano = $periodo->anoLectivo()->firstOrFail();

        if ($ano->estaCerrado()) {
            abort(422, 'El año lectivo está cerrado y sus datos son inmutables.');
        }

        if ($periodo->estaCerrado()) {
            abort(422, 'El periodo está cerrado y no puede eliminarse.');
        }

        if ($ano->estado !== AnoLectivo::ESTADO_PLANIFICADO) {
            abort(422, 'Solo se pueden eliminar periodos mientras el año lectivo está planificado.');
        }

        $prev = $this->snapshot($periodo);
        $periodo->delete();

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
        $periodo = Periodo::findOrFail($id);
        $ano = $periodo->anoLectivo()->firstOrFail();

        if ($ano->estado !== AnoLectivo::ESTADO_EN_CURSO) {
            abort(422, 'Solo se pueden abrir periodos de un año lectivo en curso.');
        }

        if ($periodo->estado !== Periodo::ESTADO_PLANIFICADO) {
            abort(422, 'Solo un periodo en estado planificado puede abrirse.');
        }

        $prev = $this->snapshot($periodo);
        $periodo->update(['estado' => Periodo::ESTADO_ABIERTO]);

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
        $periodo = Periodo::findOrFail($id);

        if ($periodo->estado !== Periodo::ESTADO_ABIERTO) {
            abort(422, 'Solo un periodo abierto puede cerrarse.');
        }

        $prev = $this->snapshot($periodo);
        $periodo->update(['estado' => Periodo::ESTADO_CERRADO]);

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

    /**
     * Reglas de validación compartidas por store/update.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'orden' => ['required', 'integer', 'min:1', 'max:12'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'peso' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
    }

    /**
     * El orden del periodo no debe exceder el número de periodos del año
     * (+1 si el año maneja quinto periodo — RN-PA-008).
     */
    private function validarOrdenDentroDelLimite(AnoLectivo $ano, int $orden): void
    {
        $maximo = $ano->num_periodos + ($ano->tiene_quinto_periodo ? 1 : 0);

        if ($orden > $maximo) {
            abort(422, "El orden del periodo excede el número de periodos del año lectivo (máximo {$maximo}).");
        }
    }

    /**
     * Valida que las fechas caigan dentro del año y que los periodos sean
     * contiguos (RN-PA-003): el periodo N+1 inicia el día siguiente al cierre
     * del periodo N.
     *
     * @param  array<string, mixed>  $data
     */
    private function validarFechasYContiguidad(AnoLectivo $ano, array $data, ?int $ignorarId = null): void
    {
        $inicio = Carbon::parse($data['fecha_inicio'])->startOfDay();
        $fin = Carbon::parse($data['fecha_fin'])->startOfDay();

        // Dentro del rango del año lectivo.
        if ($inicio->lt($ano->fecha_inicio->copy()->startOfDay()) || $fin->gt($ano->fecha_fin->copy()->startOfDay())) {
            abort(422, 'Las fechas del periodo deben estar dentro del rango del año lectivo.');
        }

        // Construye el conjunto ordenado de periodos (existentes + el entrante).
        $periodos = $ano->periodos()
            ->when($ignorarId !== null, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->get(['id', 'orden', 'fecha_inicio', 'fecha_fin'])
            ->map(fn (Periodo $p) => [
                'orden' => $p->orden,
                'fecha_inicio' => $p->fecha_inicio->copy()->startOfDay(),
                'fecha_fin' => $p->fecha_fin->copy()->startOfDay(),
            ])
            ->push([
                'orden' => (int) $data['orden'],
                'fecha_inicio' => $inicio,
                'fecha_fin' => $fin,
            ])
            ->sortBy('orden')
            ->values();

        $anterior = null;
        foreach ($periodos as $p) {
            if ($anterior !== null) {
                $esperado = $anterior['fecha_fin']->copy()->addDay();
                if (! $p['fecha_inicio']->isSameDay($esperado)) {
                    abort(422, 'Los periodos deben ser contiguos: cada periodo inicia el día siguiente al cierre del anterior (RN-PA-003).');
                }
            }
            $anterior = $p;
        }

        $esperados = $ano->num_periodos + ($ano->tiene_quinto_periodo ? 1 : 0);
        if ($periodos->count() === $esperados) {
            $primero = $periodos->first();
            $ultimo = $periodos->last();

            if (! $primero['fecha_inicio']->isSameDay($ano->fecha_inicio)
                || ! $ultimo['fecha_fin']->isSameDay($ano->fecha_fin)) {
                abort(422, 'El conjunto completo de periodos debe cubrir desde el inicio hasta el cierre del año lectivo.');
            }
        }
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Periodo $periodo): array
    {
        return [
            'id' => $periodo->id,
            'ano_lectivo_id' => $periodo->ano_lectivo_id,
            'nombre' => $periodo->nombre,
            'orden' => $periodo->orden,
            'fecha_inicio' => $periodo->fecha_inicio?->toDateString(),
            'fecha_fin' => $periodo->fecha_fin?->toDateString(),
            'peso' => $periodo->peso,
            'estado' => $periodo->estado,
            'created_at' => $periodo->created_at?->toIso8601String(),
        ];
    }
}
