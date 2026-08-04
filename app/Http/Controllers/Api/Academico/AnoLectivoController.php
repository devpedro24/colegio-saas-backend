<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Services\ConfigurationGate;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestión de años lectivos del colegio (RN-PA-001..008). Rutas del tenant.
 *
 * El backend es la AUTORIDAD de la FSM del año lectivo:
 *   planificado → en_curso → cerrado → archivado.
 * Solo un año puede estar 'en_curso' a la vez (RN-PA-001) y el tipo de
 * calendario es inmutable si ya hay años activos/cerrados (RN-PA-007).
 */
class AnoLectivoController extends Controller
{
    /** Lista los años lectivos del colegio (más recientes primero). */
    public function index(): JsonResponse
    {
        $anos = AnoLectivo::query()
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AnoLectivo $ano) => $this->present($ano));

        return response()->json(['data' => $anos]);
    }

    /** Detalle de un año lectivo (incluye sus periodos). */
    public function show(string $id): JsonResponse
    {
        $ano = AnoLectivo::with('periodos')->findOrFail($id);

        return response()->json(['data' => $this->present($ano, true)]);
    }

    /** Crea un año lectivo (nace en estado 'planificado'). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', 'unique:anos_lectivos,nombre'],
            'tipo_calendario' => ['required', Rule::in([AnoLectivo::TIPO_A, AnoLectivo::TIPO_B])],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after:fecha_inicio'],
            'num_periodos' => ['nullable', 'integer', 'min:1', 'max:12'],
            'tiene_quinto_periodo' => ['nullable', 'boolean'],
        ]);

        // RN-PA-002: el nombre debe respetar el formato del tipo de calendario.
        $this->validarNombreSegunCalendario($data['tipo_calendario'], $data['nombre']);

        $ano = AnoLectivo::create([
            'nombre' => $data['nombre'],
            'tipo_calendario' => $data['tipo_calendario'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'num_periodos' => $data['num_periodos'] ?? 4,
            'tiene_quinto_periodo' => $data['tiene_quinto_periodo'] ?? false,
            'estado' => AnoLectivo::ESTADO_PLANIFICADO,
        ]);

        AuditLogger::tenant(
            $request->user(),
            'CREATE',
            'ano_lectivo',
            (string) $ano->id,
            null,
            $this->snapshot($ano),
        );

        // Gate de operabilidad: la creacion del primer ano lectivo completa el
        // bloque 'calendario'; si con esto se completa la config minima, activa.
        ConfigurationGate::maybeActivate($request->user());

        return response()->json(['data' => $this->present($ano)], 201);
    }

    /** Edita un año lectivo (bloqueado si está cerrado/archivado — RN-PA-006). */
    public function update(Request $request, string $id): JsonResponse
    {
        $ano = AnoLectivo::findOrFail($id);

        // RN-PA-006: un año cerrado/archivado es completamente inmutable.
        if ($ano->estaCerrado()) {
            abort(422, 'El año lectivo está cerrado y sus datos son inmutables.');
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', Rule::unique('anos_lectivos', 'nombre')->ignore($ano->id)],
            'tipo_calendario' => ['required', Rule::in([AnoLectivo::TIPO_A, AnoLectivo::TIPO_B])],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after:fecha_inicio'],
            'num_periodos' => ['nullable', 'integer', 'min:1', 'max:12'],
            'tiene_quinto_periodo' => ['nullable', 'boolean'],
        ]);

        // RN-PA-007: el tipo de calendario no puede cambiar si ya hay años activos o cerrados.
        if ($data['tipo_calendario'] !== $ano->tipo_calendario) {
            $hayActivosOCerrados = AnoLectivo::query()
                ->whereIn('estado', [
                    AnoLectivo::ESTADO_EN_CURSO,
                    AnoLectivo::ESTADO_CERRADO,
                    AnoLectivo::ESTADO_ARCHIVADO,
                ])
                ->exists();

            if ($hayActivosOCerrados) {
                abort(422, 'No se puede cambiar el tipo de calendario: existen años lectivos activos o cerrados (RN-PA-007).');
            }
        }

        // RN-PA-002: el nombre debe respetar el formato del tipo de calendario.
        $this->validarNombreSegunCalendario($data['tipo_calendario'], $data['nombre']);

        $prev = $this->snapshot($ano);

        $ano->update([
            'nombre' => $data['nombre'],
            'tipo_calendario' => $data['tipo_calendario'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'num_periodos' => $data['num_periodos'] ?? $ano->num_periodos,
            'tiene_quinto_periodo' => $data['tiene_quinto_periodo'] ?? $ano->tiene_quinto_periodo,
        ]);

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'ano_lectivo',
            (string) $ano->id,
            $prev,
            $this->snapshot($ano),
        );

        return response()->json(['data' => $this->present($ano)]);
    }

    /**
     * Transición: planificado → en_curso (RN-PA-001).
     * Valida que no exista otro año lectivo 'en_curso'.
     */
    public function iniciar(Request $request, string $id): JsonResponse
    {
        $ano = AnoLectivo::findOrFail($id);

        if ($ano->estado !== AnoLectivo::ESTADO_PLANIFICADO) {
            abort(422, 'Solo un año lectivo en estado planificado puede iniciarse.');
        }

        // RN-PA-001: un único año lectivo en curso a la vez.
        $otroEnCurso = AnoLectivo::query()
            ->where('estado', AnoLectivo::ESTADO_EN_CURSO)
            ->where('id', '!=', $ano->id)
            ->exists();

        if ($otroEnCurso) {
            abort(422, 'Ya existe un año lectivo en curso. Solo puede haber uno a la vez (RN-PA-001).');
        }

        $prev = $this->snapshot($ano);
        $ano->update(['estado' => AnoLectivo::ESTADO_EN_CURSO]);

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'ano_lectivo',
            (string) $ano->id,
            $prev,
            $this->snapshot($ano),
            'Inicio del año lectivo (planificado → en_curso).',
        );

        return response()->json(['data' => $this->present($ano)]);
    }

    /** Transición: en_curso → cerrado (RN-PA-006, datos inmutables tras el cierre). */
    public function cerrar(Request $request, string $id): JsonResponse
    {
        $ano = AnoLectivo::findOrFail($id);

        if ($ano->estado !== AnoLectivo::ESTADO_EN_CURSO) {
            abort(422, 'Solo un año lectivo en curso puede cerrarse.');
        }

        $prev = $this->snapshot($ano);
        $ano->update(['estado' => AnoLectivo::ESTADO_CERRADO]);

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'ano_lectivo',
            (string) $ano->id,
            $prev,
            $this->snapshot($ano),
            'Cierre del año lectivo (en_curso → cerrado).',
        );

        return response()->json(['data' => $this->present($ano)]);
    }

    /**
     * RN-PA-002: valida el formato del nombre según el tipo de calendario.
     *   - A: "AAAA" (año simple, p.ej. "2026").
     *   - B: "AAAA-AAAA" con años consecutivos (p.ej. "2025-2026").
     */
    private function validarNombreSegunCalendario(string $tipo, string $nombre): void
    {
        if ($tipo === AnoLectivo::TIPO_A) {
            if (preg_match('/^\d{4}$/', $nombre) !== 1) {
                abort(422, 'El Calendario A usa un año simple con formato "AAAA" (p.ej. "2026").');
            }

            return;
        }

        // Calendario B: "AAAA-AAAA" con el segundo año consecutivo al primero.
        if (preg_match('/^(\d{4})-(\d{4})$/', $nombre, $m) !== 1) {
            abort(422, 'El Calendario B usa el formato "AAAA-AAAA" (p.ej. "2025-2026").');
        }

        if ((int) $m[2] !== (int) $m[1] + 1) {
            abort(422, 'El Calendario B requiere años consecutivos en "AAAA-AAAA" (p.ej. "2025-2026").');
        }
    }

    /**
     * Instantánea de auditoría del año lectivo.
     *
     * @return array<string, mixed>
     */
    private function snapshot(AnoLectivo $ano): array
    {
        return [
            'nombre' => $ano->nombre,
            'tipo_calendario' => $ano->tipo_calendario,
            'fecha_inicio' => $ano->fecha_inicio?->toDateString(),
            'fecha_fin' => $ano->fecha_fin?->toDateString(),
            'num_periodos' => $ano->num_periodos,
            'tiene_quinto_periodo' => $ano->tiene_quinto_periodo,
            'estado' => $ano->estado,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AnoLectivo $ano, bool $conPeriodos = false): array
    {
        $payload = [
            'id' => $ano->id,
            'nombre' => $ano->nombre,
            'tipo_calendario' => $ano->tipo_calendario,
            'fecha_inicio' => $ano->fecha_inicio?->toDateString(),
            'fecha_fin' => $ano->fecha_fin?->toDateString(),
            'num_periodos' => $ano->num_periodos,
            'tiene_quinto_periodo' => $ano->tiene_quinto_periodo,
            'estado' => $ano->estado,
            'created_at' => $ano->created_at?->toIso8601String(),
        ];

        if ($conPeriodos) {
            $payload['periodos'] = $ano->periodos->map(fn ($periodo) => [
                'id' => $periodo->id,
                'nombre' => $periodo->nombre,
                'orden' => $periodo->orden,
                'fecha_inicio' => $periodo->fecha_inicio?->toDateString(),
                'fecha_fin' => $periodo->fecha_fin?->toDateString(),
                'peso' => $periodo->peso,
                'estado' => $periodo->estado,
            ])->values();
        }

        return $payload;
    }
}
