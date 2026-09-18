<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Events\TenantDataChanged;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Periodo;
use App\Services\ConfigurationGate;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            ->withCount('periodos')
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
            'num_periodos' => ['required', 'integer', 'min:1', 'max:4'],
            'tiene_quinto_periodo' => ['nullable', 'boolean'],
        ]);

        // RN-PA-002: el nombre debe respetar el formato del tipo de calendario.
        $this->validarNombreSegunCalendario($data['tipo_calendario'], $data['nombre']);

        $this->validarFechasSegunCalendario($data);

        $ano = AnoLectivo::create([
            'nombre' => $data['nombre'],
            'tipo_calendario' => $data['tipo_calendario'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'num_periodos' => $data['num_periodos'],
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

        try {
            TenantDataChanged::dispatch('ano_lectivo', 'created', $data['nombre']);
        } catch (\Throwable) {}

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
            'num_periodos' => ['required', 'integer', 'min:1', 'max:4'],
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

        $this->validarFechasSegunCalendario($data);

        $prev = $this->snapshot($ano);

        $ano->update([
            'nombre' => $data['nombre'],
            'tipo_calendario' => $data['tipo_calendario'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'num_periodos' => $data['num_periodos'],
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

        try {
            TenantDataChanged::dispatch('ano_lectivo', 'updated', $ano->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->present($ano)]);
    }

    /** Elimina físicamente un año vacío. Exclusivo del superadministrador de plataforma. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->esSuperadminPlataforma()) {
            abort(403, 'Solo el superadministrador de la plataforma puede eliminar años lectivos.');
        }

        $ano = AnoLectivo::findOrFail($id);

        if ($this->tieneInformacionAnclada($ano)) {
            abort(422, 'No se puede eliminar el año lectivo porque tiene notas, informes u otra información asociada.');
        }

        $prev = $this->snapshot($ano);

        DB::transaction(function () use ($ano): void {
            // El guard anterior garantiza que solo queden períodos de configuración vacíos.
            Periodo::withTrashed()
                ->where('ano_lectivo_id', $ano->id)
                ->forceDelete();
            $ano->forceDelete();
        });

        AuditLogger::tenant(
            $request->user(),
            'DELETE',
            'ano_lectivo',
            (string) $ano->id,
            $prev,
            null,
        );

        try {
            TenantDataChanged::dispatch('ano_lectivo', 'deleted', $ano->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => null]);
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
        $this->validarFechasSegunCalendario([
            'tipo_calendario' => $ano->tipo_calendario,
            'nombre' => $ano->nombre,
            'fecha_inicio' => $ano->fecha_inicio->toDateString(),
            'fecha_fin' => $ano->fecha_fin->toDateString(),
        ]);
        $hoy = now()->startOfDay();
        if ($hoy->lt($ano->fecha_inicio) || $hoy->gt($ano->fecha_fin)) {
            abort(422, "No se puede iniciar este año lectivo fuera de su rango ({$ano->fecha_inicio->toDateString()} a {$ano->fecha_fin->toDateString()}).");
        }

        $this->validarPeriodosCompletos($ano);

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

        
        try {
            TenantDataChanged::dispatch('ano_lectivo', 'updated', $ano->nombre);
        } catch (\Throwable) {}

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

        
        try {
            TenantDataChanged::dispatch('ano_lectivo', 'updated', $ano->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $this->present($ano)]);
    }

    /** Reabre un año cerrado. Es una corrección excepcional, exclusiva de plataforma. */
    public function reabrir(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->esSuperadminPlataforma()) {
            abort(403, 'Solo el superadministrador de la plataforma puede reabrir años lectivos.');
        }

        $ano = AnoLectivo::findOrFail($id);
        if ($ano->estado !== AnoLectivo::ESTADO_CERRADO) {
            abort(422, 'Solo un año lectivo cerrado puede reabrirse.');
        }

        if (AnoLectivo::query()->enCurso()->where('id', '!=', $ano->id)->exists()) {
            abort(422, 'No se puede reabrir el año lectivo porque ya existe otro año en curso.');
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
            'Reapertura excepcional por superadministrador (cerrado → en_curso).',
        );

        try {
            TenantDataChanged::dispatch('ano_lectivo', 'updated', $ano->nombre);
        } catch (\Throwable) {}

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
    /**
     * Las fechas pueden ser cualquier rango dentro de los meses definidos por
     * el calendario. Los extremos 01/01, 31/12, 01/08 y 31/07 son valores
     * sugeridos, no una obligación.
     *
     * @param array{tipo_calendario:string,nombre:string,fecha_inicio:string,fecha_fin:string} $data
     */
    private function validarFechasSegunCalendario(array $data): void
    {
        $inicio = Carbon::parse($data['fecha_inicio'])->startOfDay();
        $fin = Carbon::parse($data['fecha_fin'])->startOfDay();

        if ($data['tipo_calendario'] === AnoLectivo::TIPO_A) {
            $limiteInicio = Carbon::parse("{$data['nombre']}-01-01");
            $limiteFin = Carbon::parse("{$data['nombre']}-12-31");
        } else {
            [$anoInicial] = explode('-', $data['nombre']);
            $siguiente = (string) ((int) $anoInicial + 1);
            $limiteInicio = Carbon::parse("{$anoInicial}-08-01");
            $limiteFin = Carbon::parse("{$siguiente}-07-31");
        }

        if ($inicio->lt($limiteInicio) || $fin->gt($limiteFin)) {
            abort(422, sprintf(
                'El Calendario %s debe estar dentro del rango %s al %s.',
                $data['tipo_calendario'],
                $limiteInicio->toDateString(),
                $limiteFin->toDateString(),
            ));
        }
    }

    /** Verifica que los períodos exigidos estén completos, consecutivos y cubran todo el año. */
    private function validarPeriodosCompletos(AnoLectivo $ano): void
    {
        $periodos = $ano->periodos()->get();
        $esperados = $ano->num_periodos + ($ano->tiene_quinto_periodo ? 1 : 0);

        if ($periodos->count() !== $esperados) {
            abort(422, "No se puede iniciar el año lectivo: debes configurar sus {$esperados} períodos.");
        }

        foreach ($periodos as $indice => $periodo) {
            if ($periodo->orden !== $indice + 1) {
                abort(422, 'No se puede iniciar el año lectivo: los períodos deben estar configurados en orden consecutivo.');
            }

            if ($indice === 0) {
                if (! $periodo->fecha_inicio->isSameDay($ano->fecha_inicio)) {
                    abort(422, 'No se puede iniciar el año lectivo: el primer período debe comenzar en la fecha de inicio del año.');
                }

                continue;
            }

            $anterior = $periodos[$indice - 1];
            if (! $periodo->fecha_inicio->isSameDay($anterior->fecha_fin->copy()->addDay())) {
                abort(422, 'No se puede iniciar el año lectivo: los períodos deben ser consecutivos y no pueden dejar fechas sin configurar.');
            }
        }

        if (! $periodos->last()->fecha_fin->isSameDay($ano->fecha_fin)) {
            abort(422, 'No se puede iniciar el año lectivo: el último período debe terminar en la fecha de cierre del año.');
        }
    }

    /**
     * Un año solo puede borrarse físicamente si no tiene nada anclado. Se revisan
     * referencias directas al año y referencias a cualquiera de sus períodos.
     * Los módulos académicos deben usar ano_lectivo_id/academic_year_id y
     * periodo_id/period_id para quedar protegidos automáticamente.
     */
    private function tieneInformacionAnclada(AnoLectivo $ano): bool
    {
        $periodoIds = Periodo::withTrashed()
            ->where('ano_lectivo_id', $ano->id)
            ->pluck('id')
            ->all();

        try {
            foreach (Schema::getTables() as $tabla) {
                $nombre = is_array($tabla) ? $tabla['name'] : $tabla->name;
                if (in_array($nombre, [$ano->getTable(), 'periodos'], true)) {
                    continue;
                }

                $columnas = Schema::getColumnListing($nombre);
                foreach (['ano_lectivo_id', 'academic_year_id'] as $columnaAno) {
                    if (in_array($columnaAno, $columnas, true)
                        && DB::table($nombre)->where($columnaAno, $ano->getKey())->exists()) {
                        return true;
                    }
                }

                if ($periodoIds === []) {
                    continue;
                }

                foreach (['periodo_id', 'period_id'] as $columnaPeriodo) {
                    if (in_array($columnaPeriodo, $columnas, true)
                        && DB::table($nombre)->whereIn($columnaPeriodo, $periodoIds)->exists()) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            // Ante una falla de inspección se preservan los datos por seguridad.
            abort(422, 'No se pudo comprobar si el año lectivo tiene información asociada; no se eliminó.');
        }

        return false;
    }

    private function snapshot(AnoLectivo $ano): array
    {
        return [
            'nombre' => $ano->nombre,
            'tipo_calendario' => $ano->tipo_calendario,
            'fecha_inicio' => $ano->fecha_inicio?->toDateString(),
            'fecha_fin' => $ano->fecha_fin?->toDateString(),
            'num_periodos' => $ano->num_periodos,
            'tiene_quinto_periodo' => $ano->tiene_quinto_periodo,
            'periodos_configurados' => $ano->periodos_count,
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
