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
use App\Services\AcademicRecoveryService;
use App\Services\GradebookService;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecuperacionAcademicaController extends Controller
{
    use PaginatesRequests;

    public function __construct(private AcademicRecoveryService $recoveries, private GradebookService $book) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $manager = $actor->can('notas.gestionar_nivelaciones');
        $teacher = $actor->can('notas.registrar_materia_asignada');
        $student = $actor->can('notas.ver_propias');
        abort_unless($manager || $teacher || $student, 403);
        $query = RecuperacionAcademica::with(['matricula.estudiante:id,name', 'asignacion.materia:id,nombre',
            'asignacion.grupo.grado', 'periodo:id,nombre']);
        if (! $manager) {
            $query->where(function ($scope) use ($actor, $teacher, $student) {
                if ($teacher) {
                    $scope->whereHas('asignacion', fn ($assignments) => $assignments->where('docente_id', $actor->id));
                }
                if ($student) {
                    $method = $teacher ? 'orWhereHas' : 'whereHas';
                    $scope->{$method}('matricula', fn ($enrollments) => $enrollments->where('estudiante_id', $actor->id));
                }
            });
        }
        $query->when($request->filled('ano_lectivo_id'), fn ($q) => $q->where('ano_lectivo_id', $request->integer('ano_lectivo_id')))
            ->when($request->filled('matricula_id'), fn ($q) => $q->where('matricula_id', $request->integer('matricula_id')))
            ->when($request->filled('asignacion_id'), fn ($q) => $q->where('asignacion_id', $request->integer('asignacion_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->query('estado')));
        $page = $this->paginateAcademic($query->orderByDesc('id'), $request);

        $candidates = [];
        $context = null;
        if ($manager && $request->filled('matricula_id')) {
            $enrollment = Matricula::with(['estudiante:id,name', 'grupo.grado'])->findOrFail($request->integer('matricula_id'));
            $candidates = $this->candidates($enrollment);
            $context = ['estudiante' => $enrollment->estudiante->name,
                'grado' => $enrollment->grupo->grado->nombre, 'grupo' => $enrollment->grupo->nombre,
                'ano_lectivo_token' => OpaqueUrlToken::for('ano-lectivo', $enrollment->ano_lectivo_id)];
        }

        return response()->json(['data' => $page->getCollection()->map(fn (RecuperacionAcademica $item) => [
            ...$this->present($item),
            'can_record' => $manager || ($teacher && $item->asignacion?->docente_id === $actor->id),
        ]),
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
            'asignacion.materia:id,nombre', 'asignacion.grupo.grado', 'periodo:id,nombre']))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'nota_recuperacion' => ['required', 'numeric', 'decimal:0,8'],
            'nota_manual' => ['nullable', 'numeric', 'decimal:0,8'],
            'version' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $recovery = $this->recoveries->record($request->user(), RecuperacionAcademica::findOrFail($id),
            (string) $data['nota_recuperacion'], isset($data['nota_manual']) ? (string) $data['nota_manual'] : null,
            $data['version'], $data['motivo']);

        return response()->json(['data' => $this->present($recovery->load(['matricula.estudiante:id,name',
            'asignacion.materia:id,nombre', 'asignacion.grupo.grado', 'periodo:id,nombre']))]);
    }

    public function anular(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:3', 'max:500']]);
        $recovery = $this->recoveries->cancel($request->user(), RecuperacionAcademica::findOrFail($id),
            $data['version'], $data['motivo']);

        return response()->json(['data' => $this->present($recovery->load(['matricula.estudiante:id,name',
            'asignacion.materia:id,nombre', 'asignacion.grupo.grado', 'periodo:id,nombre']))]);
    }

    private function present(RecuperacionAcademica $item): array
    {
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

    private function candidates(Matricula $enrollment): array
    {
        $year = AnoLectivo::findOrFail($enrollment->ano_lectivo_id);
        if ($year->estado !== AnoLectivo::ESTADO_EN_CURSO || $enrollment->estado !== 'activa') {
            return [];
        }
        $report = $this->book->report($enrollment);
        $assignments = AsignacionDocente::where('grupo_id', $enrollment->grupo_id)
            ->where('ano_lectivo_id', $year->id)->get()->keyBy('materia_id');
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
                if ($period && $period['estado'] === Periodo::ESTADO_CERRADO
                    && $result['estado'] === 'calculado' && ! $result['aprobado']
                    && ! isset($existing[$scope])) {
                    $candidates[] = ['asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $assignment->id),
                        'periodo_token' => OpaqueUrlToken::for('periodo', $result['periodo_id']),
                        'materia' => $subject['nombre'], 'periodo' => $period['nombre'],
                        'valor_original' => $result['display_value'], 'tipo' => 'nivelacion'];
                }
            }
            $scope = $assignment->id.':anual';
            if ($annualReady && $subject['anual']['estado'] === 'calculado'
                && ! $subject['anual']['aprobado'] && ! isset($existing[$scope])) {
                $candidates[] = ['asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $assignment->id),
                    'periodo_token' => null, 'materia' => $subject['nombre'],
                    'periodo' => null, 'valor_original' => $subject['anual']['display_value'],
                    'tipo' => 'habilitacion'];
            }
        }

        return $candidates;
    }
}
