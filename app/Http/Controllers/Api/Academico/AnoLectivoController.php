<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Periodo;
use App\Services\AcademicYearReviewService;
use App\Services\ConfigurationGate;
use App\Services\DuplicarAnoLectivoService;
use App\Services\GradeCalculationService;
use App\Services\PeriodoLifecycleService;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $ano = AnoLectivo::findOrFail($id);
        app(PeriodoLifecycleService::class)->synchronize($ano);
        $ano->load('periodos');

        return response()->json(['data' => $this->present($ano, true)]);
    }

    public function estadoCopia(string $id, DuplicarAnoLectivoService $service): JsonResponse
    {
        $status = $service->copyStatus(AnoLectivo::findOrFail($id));
        if (request()->boolean('opaque')) {
            $sourceId = $status['origen_id'];
            unset($status['origen_id']);
            $status['origen_token'] = $sourceId === null ? null : OpaqueUrlToken::for('ano-lectivo', $sourceId);
        }

        return response()->json(['data' => $status]);
    }

    /** Preflight del cierre; nunca modifica resultados ni crea promociones. */
    public function revisionCierre(Request $request, string $id, AcademicYearReviewService $service): JsonResponse
    {
        $this->assertTransitionActor($request);

        return response()->json(['data' => $service->review(AnoLectivo::findOrFail($id))]);
    }

    /** Crea un año lectivo (nace en estado 'planificado'). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', 'unique:anos_lectivos,nombre'],
            'tipo_calendario' => ['required', Rule::in([AnoLectivo::TIPO_A, AnoLectivo::TIPO_B])],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after:fecha_inicio'],
            'num_periodos' => ['required', 'integer', 'min:1', 'max:12'],
            'periodo_sumatorio' => ['nullable', 'boolean'],
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
            'periodo_sumatorio' => $data['periodo_sumatorio'] ?? false,
            'estado' => AnoLectivo::ESTADO_PLANIFICADO,
        ]);

        // Colegios antiguos podían configurar catálogos antes de crear el primer año.
        if (AnoLectivo::count() === 1) {
            foreach (['jornadas', 'niveles', 'grados', 'bloques_horarios', 'espacios_fisicos', 'areas', 'materias'] as $table) {
                DB::table($table)->whereNull('ano_lectivo_id')->update(['ano_lectivo_id' => $ano->id]);
            }
        }

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
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->present($ano)], 201);
    }

    /** Crea un año planificado y copia únicamente las configuraciones elegidas. */
    public function duplicar(Request $request, string $id, DuplicarAnoLectivoService $service): JsonResponse
    {
        $source = AnoLectivo::findOrFail($id);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', 'unique:anos_lectivos,nombre'],
            'tipo_calendario' => ['required', Rule::in([AnoLectivo::TIPO_A, AnoLectivo::TIPO_B])],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after:fecha_inicio'],
            'num_periodos' => ['required', 'integer', 'min:1', 'max:12'],
            'periodo_sumatorio' => ['nullable', 'boolean'],
            'opciones' => ['required', 'array'],
            'opciones.*' => ['boolean'],
        ]);
        $this->validarNombreSegunCalendario($data['tipo_calendario'], $data['nombre']);
        $this->validarFechasSegunCalendario($data);
        if ($data['tipo_calendario'] !== $source->tipo_calendario && AnoLectivo::query()
            ->whereIn('estado', [AnoLectivo::ESTADO_EN_CURSO, AnoLectivo::ESTADO_CERRADO, AnoLectivo::ESTADO_ARCHIVADO])->exists()) {
            abort(422, 'No se puede cambiar el tipo de calendario mientras existan años lectivos en curso o cerrados.');
        }
        $target = $service->duplicar($source, [
            'nombre' => $data['nombre'],
            'tipo_calendario' => $data['tipo_calendario'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'num_periodos' => $data['num_periodos'],
            'periodo_sumatorio' => $data['periodo_sumatorio'] ?? false,
        ], $data['opciones'], $request->user());

        return response()->json(['data' => $this->present($target)], 201);
    }

    /** Copia secciones pendientes a un año ya existente sin sobrescribirlo. */
    public function copiarConfiguracion(Request $request, string $id, DuplicarAnoLectivoService $service): JsonResponse
    {
        $target = AnoLectivo::findOrFail($id);
        $opaque = $request->boolean('opaque');
        $data = $request->validate([
            'origen_id' => [$opaque ? 'prohibited' : 'required', 'integer', 'exists:anos_lectivos,id'],
            'origen_token' => [$opaque ? 'required' : 'prohibited', 'string', 'size:24'],
            'opciones' => ['required', 'array:'.implode(',', DuplicarAnoLectivoService::OPTIONS)],
            'opciones.*' => ['boolean'],
        ]);
        $source = $opaque
            ? OpaqueUrlToken::find('ano-lectivo', $data['origen_token'], AnoLectivo::query())
            : AnoLectivo::findOrFail($data['origen_id']);
        abort_unless($source instanceof AnoLectivo, 404);
        $updated = $service->copiarConfiguracion($source, $target, $data['opciones'], $request->user());

        return response()->json(['data' => $this->present($updated)]);
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
            'num_periodos' => ['required', 'integer', 'min:1', 'max:12'],
            'periodo_sumatorio' => ['nullable', 'boolean'],
        ]);

        $nuevoTotalPeriodos = $data['num_periodos'];
        if (($data['periodo_sumatorio'] ?? $ano->periodo_sumatorio)
            && ($ano->siee['modo_anual'] ?? null) === 'MANUAL') {
            abort(422, 'El período sumatorio requiere un cálculo anual simple o ponderado en el SIEE.');
        }
        $ultimoPeriodoConfigurado = (int) $ano->periodos()->max('orden');
        if ($ultimoPeriodoConfigurado > $nuevoTotalPeriodos) {
            abort(422, "El periodo {$ultimoPeriodoConfigurado} ya está configurado. Elimínalo desde Periodos antes de reducir la cantidad.");
        }

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

        if ($ano->periodos()->where(function ($query) use ($data) {
            $query->whereDate('fecha_inicio', '<', $data['fecha_inicio'])
                ->orWhereDate('fecha_fin', '>', $data['fecha_fin']);
        })->exists()) {
            abort(422, 'Ajusta primero los períodos: sus fechas quedarían fuera del nuevo rango del año lectivo.');
        }

        $prev = $this->snapshot($ano);

        $ano->update([
            'nombre' => $data['nombre'],
            'tipo_calendario' => $data['tipo_calendario'],
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'num_periodos' => $data['num_periodos'],
            'periodo_sumatorio' => $data['periodo_sumatorio'] ?? $ano->periodo_sumatorio,
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
        } catch (\Throwable) {
        }

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
        } catch (\Throwable) {
        }

        return response()->json(['data' => null]);
    }

    /**
     * Transición: planificado → en_curso (RN-PA-001).
     * Valida que no exista otro año lectivo 'en_curso'.
     */
    public function iniciar(Request $request, string $id): JsonResponse
    {
        $this->assertTransitionActor($request);
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
        $hoy = Carbon::parse(PeriodoLifecycleService::today())->startOfDay();
        if ($hoy->lt($ano->fecha_inicio) || $hoy->gt($ano->fecha_fin)) {
            abort(422, "No se puede iniciar este año lectivo fuera de su rango ({$ano->fecha_inicio->toDateString()} a {$ano->fecha_fin->toDateString()}).");
        }

        $this->validarPeriodosCompletos($ano);
        if ($ano->periodo_sumatorio && ($ano->siee['modo_anual'] ?? null) === 'MANUAL') {
            abort(422, 'El período sumatorio requiere un cálculo anual simple o ponderado en el SIEE.');
        }

        $otroEnCurso = AnoLectivo::query()
            ->where('estado', AnoLectivo::ESTADO_EN_CURSO)
            ->where('id', '!=', $ano->id)
            ->exists();

        if ($otroEnCurso) {
            abort(422, 'Ya existe un año lectivo en curso. Solo puede haber uno a la vez (RN-PA-001).');
        }

        $prev = $this->snapshot($ano);
        $ano->update(['estado' => AnoLectivo::ESTADO_EN_CURSO]);
        app(PeriodoLifecycleService::class)->synchronize($ano);

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
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->present($ano)]);
    }

    /** Cierre tras revisión de períodos y decisiones de promoción, o confirmación de año vacío. */
    public function cerrar(Request $request, string $id, ?AcademicYearReviewService $service = null): JsonResponse
    {
        $this->assertTransitionActor($request);
        $service ??= app(AcademicYearReviewService::class);
        $request->validate(['confirmar_sin_matriculas' => ['sometimes', 'accepted'],
            'confirmar_promociones' => ['sometimes', 'accepted'],
            'confirmar_solo_retiradas' => ['sometimes', 'accepted']]);
        [$ano, $prev, $review] = DB::transaction(function () use ($id, $service, $request): array {
            $ano = AnoLectivo::query()->lockForUpdate()->findOrFail($id);
            $periods = $ano->periodos()->lockForUpdate()->get();
            $review = $service->review($ano, $periods);
            abort_if(! $review['puede_cerrar'], 422,
                'No se puede cerrar el año lectivo. Revisa los períodos y la matrícula académica antes de confirmar.');
            if ($review['sin_matriculas']) {
                if (! $request->boolean('confirmar_sin_matriculas')) {
                    throw ValidationException::withMessages(['confirmar_sin_matriculas' =>
                        'Confirma expresamente el cierre de un año sin matrículas.']);
                }
            } elseif ($review['solo_retiradas'] && ! $request->boolean('confirmar_solo_retiradas')) {
                throw ValidationException::withMessages(['confirmar_solo_retiradas' =>
                    'Confirma expresamente el cierre de un año con matrículas retiradas y sin matrículas activas.']);
            } elseif (! $review['solo_retiradas'] && ! $request->boolean('confirmar_promociones')) {
                throw ValidationException::withMessages(['confirmar_promociones' =>
                    'Confirma expresamente las decisiones de promoción revisadas.']);
            }
            $prev = $this->snapshot($ano);
            $ano->update(['estado' => AnoLectivo::ESTADO_CERRADO]);

            return [$ano, $prev, $review];
        });

        AuditLogger::tenant(
            $request->user(),
            'UPDATE',
            'ano_lectivo',
            (string) $ano->id,
            $prev,
            $this->snapshot($ano),
            'Cierre de año con revisión explícita: '.json_encode($review, JSON_UNESCAPED_UNICODE),
        );

        try {
            TenantDataChanged::dispatch('ano_lectivo', 'updated', $ano->nombre);
        } catch (\Throwable) {
        }

        return response()->json(['data' => $this->present($ano)]);
    }

    private function assertTransitionActor(Request $request): void
    {
        $actor = $request->user();
        abort_unless(
            $actor && $actor->can('academico.anos.transicionar')
                && ($actor->hasRole('rector') || $actor->esSuperadminPlataforma()),
            403,
            'Solo el rector o el superadministrador dentro del colegio puede iniciar o cerrar años lectivos.',
        );
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
        } catch (\Throwable) {
        }

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
     * @param  array{tipo_calendario:string,nombre:string,fecha_inicio:string,fecha_fin:string}  $data
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
        if (($ano->siee['modo_anual'] ?? null) === 'WEIGHTED_AVERAGE') {
            GradeCalculationService::assertWeights($periodos->pluck('peso')->all());
        }
        $esperados = $ano->num_periodos;

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
        if (Schema::hasTable('copias_configuracion_anual')
            && DB::table('copias_configuracion_anual')->where('origen_id', $ano->id)->exists()) {
            return true;
        }
        $periodoIds = Periodo::withTrashed()
            ->where('ano_lectivo_id', $ano->id)
            ->pluck('id')
            ->all();

        try {
            foreach (Schema::getTables() as $tabla) {
                $nombre = is_array($tabla) ? $tabla['name'] : $tabla->name;
                if (in_array($nombre, [$ano->getTable(), 'periodos', 'copias_configuracion_anual'], true)) {
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
            'periodo_sumatorio' => $ano->periodo_sumatorio,
            'periodos_configurados' => $ano->periodos_count,
            'estado' => $ano->estado,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AnoLectivo $ano, bool $conPeriodos = false): array
    {
        $opaque = request()->boolean('opaque');
        $payload = [
            // Stable tenant-bound URL selector; the legacy value resolves existing bookmarks.
            'url_token' => OpaqueUrlToken::for('ano-lectivo', $ano->id),
            'legacy_url_token' => hash_hmac('sha256', (string) tenancy()->tenant?->getTenantKey().'|ano-lectivo|'.$ano->id, (string) config('app.key')),
            'nombre' => $ano->nombre,
            'tipo_calendario' => $ano->tipo_calendario,
            'fecha_inicio' => $ano->fecha_inicio?->toDateString(),
            'fecha_fin' => $ano->fecha_fin?->toDateString(),
            'num_periodos' => $ano->num_periodos,
            'periodo_sumatorio' => $ano->periodo_sumatorio,
            'estado' => $ano->estado,
            'created_at' => $ano->created_at?->toIso8601String(),
        ];
        if (! $opaque) {
            $payload['id'] = $ano->id;
        }

        if ($conPeriodos) {
            $today = PeriodoLifecycleService::today();
            $payload['periodos'] = $ano->periodos->map(fn ($periodo) => [
                ...($opaque ? ['url_token' => OpaqueUrlToken::for('periodo', $periodo->id)] : ['id' => $periodo->id]),
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
            ])->values();
        }

        return $payload;
    }
}
