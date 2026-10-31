<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\RecuperacionAcademica;
use App\Models\User;
use App\Services\AcademicRecoveryService;
use App\Services\EscalaVisualService;
use App\Services\GradebookService;
use App\Services\GradeCalculationService;
use App\Services\SieeConfiguration;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecuperacionAcademicaController extends Controller
{
    use PaginatesRequests;

    private array $presentationContexts = [];

    public function __construct(private AcademicRecoveryService $recoveries, private GradebookService $book) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $manager = $actor->can('notas.gestionar_nivelaciones');
        $teacher = $actor->can('notas.registrar_materia_asignada');
        $student = $actor->can('notas.ver_propias');
        abort_unless($manager || $teacher || $student, 403);
        $query = RecuperacionAcademica::with(['matricula.estudiante:id,name', 'asignacion.materia:id,nombre',
            'asignacion.grupo.grado', 'periodo']);
        $scopedTeacher = $this->recoveries->requiresAssignment($actor);
        if (! $manager || $scopedTeacher) {
            $query->where(function ($scope) use ($actor, $teacher, $student, $scopedTeacher) {
                if ($teacher || $scopedTeacher) {
                    $scope->whereHas('asignacion', fn ($assignments) => $assignments->where('docente_id', $actor->id));
                }
                if ($student) {
                    $method = ($teacher || $scopedTeacher) ? 'orWhereHas' : 'whereHas';
                    $scope->{$method}('matricula', fn ($enrollments) => $enrollments->where('estudiante_id', $actor->id));
                }
            });
        }
        $query->when($request->filled('ano_lectivo_id'), fn ($q) => $q->where('ano_lectivo_id', $request->integer('ano_lectivo_id')))
            ->when($request->filled('matricula_id'), fn ($q) => $q->where('matricula_id', $request->integer('matricula_id')))
            ->when($request->filled('asignacion_id'), fn ($q) => $q->where('asignacion_id', $request->integer('asignacion_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->query('estado')));
        $page = $this->paginateAcademic($query
            ->orderByRaw('CASE WHEN periodo_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy(Periodo::select('orden')->whereColumn('periodos.id', 'recuperaciones_academicas.periodo_id'))
            ->orderBy('asignacion_id')->orderBy('id'), $request);

        $candidates = [];
        $context = null;
        if ($manager && $request->filled('matricula_id')) {
            $enrollment = Matricula::with(['estudiante:id,name', 'grupo.grado'])->findOrFail($request->integer('matricula_id'));
            abort_if($scopedTeacher && ! AsignacionDocente::where('docente_id', $actor->id)
                ->where('grupo_id', $enrollment->grupo_id)->where('ano_lectivo_id', $enrollment->ano_lectivo_id)->exists(), 403);
            $candidates = $this->candidates($enrollment, $actor);
            $context = ['estudiante' => $enrollment->estudiante->name,
                'grado' => $enrollment->grupo->grado->nombre, 'grupo' => $enrollment->grupo->nombre,
                'ano_lectivo_token' => OpaqueUrlToken::for('ano-lectivo', $enrollment->ano_lectivo_id)];
        }

        $years = AnoLectivo::with('periodos')->whereIn('id', $page->getCollection()->pluck('ano_lectivo_id'))->get()->keyBy('id');

        return response()->json(['data' => $page->getCollection()->map(function (RecuperacionAcademica $item) use ($actor, $years) {
            $reason = $this->recoveries->blockReason($years[$item->ano_lectivo_id], $item->periodo);
            $writable = $reason === null && $item->estado !== 'anulada' && $item->matricula?->estado === 'activa'
                && $item->asignacion && $this->recoveries->canManage($actor, $item->asignacion);

            return [...$this->present($item), 'can_record' => (bool) $writable,
                'can_cancel' => (bool) $writable, 'motivo_bloqueo' => $reason];
        }),
            'meta' => $this->paginationMeta($page),
            'can_manage' => $manager, 'candidatos' => $candidates, 'contexto' => $context]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'matricula_id' => ['required', 'integer'],
            'asignacion_id' => ['required', 'integer'],
            'periodo_id' => ['nullable', 'integer'],
            'plan_mejoramiento' => ['nullable', 'string', 'max:5000'],
        ]);
        $recovery = $this->recoveries->create($request->user(),
            Matricula::findOrFail($data['matricula_id']),
            AsignacionDocente::findOrFail($data['asignacion_id']),
            isset($data['periodo_id']) ? Periodo::findOrFail($data['periodo_id']) : null,
            $data['plan_mejoramiento'] ?? null);

        return response()->json(['data' => $this->present($recovery->load(['matricula.estudiante:id,name',
            'asignacion.materia:id,nombre', 'asignacion.grupo.grado', 'periodo']))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'nota_recuperacion' => ['nullable', 'numeric', 'decimal:0,8'],
            'nota_manual' => ['nullable', 'numeric', 'decimal:0,8'],
            'escala_opcion_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'escala_opcion_manual_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'version' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $recovery = $this->recoveries->record($request->user(), RecuperacionAcademica::findOrFail($id),
            isset($data['nota_recuperacion']) ? (string) $data['nota_recuperacion'] : null,
            isset($data['nota_manual']) ? (string) $data['nota_manual'] : null,
            $data['version'], $data['motivo'], $data['escala_opcion_token'] ?? null,
            $data['escala_opcion_manual_token'] ?? null);

        return response()->json(['data' => $this->present($recovery->load(['matricula.estudiante:id,name',
            'asignacion.materia:id,nombre', 'asignacion.grupo.grado', 'periodo']))]);
    }

    public function anular(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:3', 'max:500']]);
        $recovery = $this->recoveries->cancel($request->user(), RecuperacionAcademica::findOrFail($id),
            $data['version'], $data['motivo']);

        return response()->json(['data' => $this->present($recovery->load(['matricula.estudiante:id,name',
            'asignacion.materia:id,nombre', 'asignacion.grupo.grado', 'periodo']))]);
    }

    private function present(RecuperacionAcademica $item): array
    {
        $key = $item->asignacion->grupo_id;
        [$config, $scale] = $this->presentationContexts[$key] ??= [
            app(SieeConfiguration::class)->resolve(AnoLectivo::findOrFail($item->ano_lectivo_id)),
            app(EscalaVisualService::class)->forGroup($item->asignacion->grupo),
        ];
        $display = function (?string $value) use ($config, $scale): ?array {
            if ($value === null) {
                return null;
            }
            $result = app(GradeCalculationService::class)->result(['mode' => 'MANUAL', 'manual' => $value], $config);
            if ($scale) {
                $result = app(EscalaVisualService::class)->decorate($result, $scale->opciones);
            }

            return ['display_value' => $scale ? null : $result['display_value'],
                'valoracion' => $result['valoracion'] ?? null, 'aprobado' => $result['aprobado']];
        };

        return [
            'url_token' => OpaqueUrlToken::for('recuperacion-academica', $item->id),
            'ano_lectivo_token' => OpaqueUrlToken::for('ano-lectivo', $item->ano_lectivo_id),
            'matricula_token' => OpaqueUrlToken::for('matricula', $item->matricula_id),
            'asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $item->asignacion_id),
            'periodo_token' => $item->periodo_id ? OpaqueUrlToken::for('periodo', $item->periodo_id) : null,
            'estudiante' => $item->matricula?->estudiante?->name,
            'materia' => $item->asignacion?->materia?->nombre,
            'grupo' => $item->asignacion?->grupo?->nombre,
            'grado' => $item->asignacion?->grupo?->grado?->nombre,
            'periodo' => $item->periodo?->nombre,
            'periodo_orden' => $item->periodo?->orden,
            'periodo_estado' => $item->periodo?->estado,
            'escala_visual' => app(EscalaVisualService::class)->present($scale),
            'resultado_original' => $display($item->valor_original_exacto),
            'resultado_recuperacion' => $display($item->nota_recuperacion),
            'resultado_manual' => $display($item->nota_manual),
            'resultado_efectivo' => $display($item->valor_efectivo_exacto),
            'tipo' => $item->tipo, 'estado' => $item->estado, 'politica' => $item->politica,
            'valor_original_exacto' => $item->valor_original_exacto,
            'nota_recuperacion' => $item->nota_recuperacion,
            'nota_manual' => $item->nota_manual,
            'valor_efectivo_exacto' => $item->valor_efectivo_exacto,
            'plan_mejoramiento' => $item->plan_mejoramiento,
            'motivo' => $item->motivo, 'version' => $item->version,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    private function candidates(Matricula $enrollment, User $actor): array
    {
        $year = AnoLectivo::findOrFail($enrollment->ano_lectivo_id);
        if ($year->estado !== AnoLectivo::ESTADO_EN_CURSO || $enrollment->estado !== 'activa') {
            return [];
        }
        $report = $this->book->report($enrollment);
        $assignments = AsignacionDocente::where('grupo_id', $enrollment->grupo_id)
            ->where('ano_lectivo_id', $year->id)
            ->when($this->recoveries->requiresAssignment($actor), fn ($q) => $q->where('docente_id', $actor->id))
            ->get()->keyBy('materia_id');
        $periods = collect($report['periodos'])->keyBy('id');
        $annualReady = $periods->count() === $year->num_periodos
            && $periods->every(fn ($period) => $period['estado'] === Periodo::ESTADO_CERRADO);
        $existing = RecuperacionAcademica::where('matricula_id', $enrollment->id)
            ->where('estado', '!=', 'anulada')->get()->map(fn ($item) => $item->asignacion_id.':'.$item->alcance)->flip();
        $candidates = [];
        foreach ($report['asignaturas'] as $subject) {
            $assignment = $assignments->get($subject['materia_id']);
            if (! $assignment) {
                continue;
            }
            foreach ($subject['periodos'] as $result) {
                $period = $periods->get($result['periodo_id']);
                $scope = $assignment->id.':periodo:'.$result['periodo_id'];
                if ($period && in_array($period['estado'], [Periodo::ESTADO_ABIERTO, Periodo::ESTADO_CERRADO], true)
                    && $result['estado'] === 'calculado' && ! $result['aprobado']
                    && ! isset($existing[$scope])) {
                    $candidates[] = ['asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $assignment->id),
                        'periodo_token' => OpaqueUrlToken::for('periodo', $result['periodo_id']),
                        'materia' => $subject['nombre'], 'periodo' => $period['nombre'],
                        'periodo_orden' => $period['orden'], 'periodo_estado' => $period['estado'],
                        'valor_original' => isset($result['valoracion']) ? null : $result['display_value'],
                        'valoracion_original' => $result['valoracion'] ?? null, 'tipo' => 'nivelacion',
                        'puede_abrir' => $period['estado'] === Periodo::ESTADO_ABIERTO];
                }
            }
            $scope = $assignment->id.':anual';
            if ($annualReady && $subject['anual']['estado'] === 'calculado'
                && ! $subject['anual']['aprobado'] && ! isset($existing[$scope])) {
                $candidates[] = ['asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $assignment->id),
                    'periodo_token' => null, 'materia' => $subject['nombre'],
                    'periodo' => null, 'periodo_orden' => null, 'periodo_estado' => null,
                    'valor_original' => isset($subject['anual']['valoracion']) ? null : $subject['anual']['display_value'],
                    'valoracion_original' => $subject['anual']['valoracion'] ?? null,
                    'tipo' => 'habilitacion', 'puede_abrir' => true];
            }
        }

        return collect($candidates)->sortBy([
            fn ($a, $b) => ($a['periodo_orden'] ?? PHP_INT_MAX) <=> ($b['periodo_orden'] ?? PHP_INT_MAX),
            fn ($a, $b) => strnatcasecmp($a['materia'], $b['materia']),
        ])->values()->all();
    }
}
