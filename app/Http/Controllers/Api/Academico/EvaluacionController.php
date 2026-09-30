<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Materia;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Services\GradebookService;
use App\Services\SieeConfiguration;
use App\Support\Audit\AuditLogger;
use App\Support\EvaluationOpaquePresenter as PublicEval;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EvaluacionController extends Controller
{
    use PaginatesRequests;

    public function __construct(private GradebookService $book) {}

    public function catalogo(Request $request): JsonResponse
    {
        $user = $request->user();
        $manage = $this->book->manages($user);
        $teacher = $user->can('notas.registrar_materia_asignada');
        abort_unless($manage || $teacher || $user->can('notas.ver_propias'), 403);
        $yearId = $request->filled('ano_lectivo_id') ? $request->integer('ano_lectivo_id') : null;
        $groupId = $request->filled('grupo_id') ? $request->integer('grupo_id') : null;
        $term = trim((string) $request->query('search', ''));
        $assignmentQuery = AsignacionDocente::with(['materia:id,nombre', 'grupo.grado'])
            ->when(! $manage, fn ($q) => $q->where('docente_id', $teacher ? $user->id : -1))
            ->when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->when($groupId, fn ($q) => $q->where('grupo_id', $groupId))
            ->when($request->filled('materia_id'), fn ($q) => $q->where('materia_id', $request->integer('materia_id')))
            ->when($request->filled('docente_id'), fn ($q) => $q->where('docente_id', $request->integer('docente_id')))
            ->when($term !== '', fn ($q) => $q->where(fn ($match) => $match
                ->whereHas('materia', fn ($subject) => $subject->where('nombre', 'like', '%'.$term.'%'))
                ->orWhereHas('grupo', fn ($group) => $group->where('nombre', 'like', '%'.$term.'%'))
                ->orWhereHas('docente', fn ($teacherQuery) => $teacherQuery->where('name', 'like', '%'.$term.'%'))));
        $enrollmentQuery = Matricula::with(['estudiante:id,name', 'grupo.grado'])
            ->when(! $manage, fn ($q) => $teacher
                ? $q->whereIn('grupo_id', AsignacionDocente::where('docente_id', $user->id)->select('grupo_id'))
                : $q->where('estudiante_id', $user->id))
            ->when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->when($groupId, fn ($q) => $q->where('grupo_id', $groupId))
            ->when($request->filled('estudiante_id'), fn ($q) => $q->where('estudiante_id', $request->integer('estudiante_id')))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->query('estado')))
            ->when($term !== '', fn ($q) => $q->where(fn ($match) => $match
                ->whereHas('estudiante', fn ($studentQuery) => $studentQuery->where('name', 'like', '%'.$term.'%'))
                ->orWhereHas('grupo', fn ($group) => $group->where('nombre', 'like', '%'.$term.'%'))));
        $selectedAssignment = $this->selectedByToken($assignmentQuery, 'asignacion-docente', $request->query('asignacion_token'));
        $selectedEnrollment = $this->selectedByToken($enrollmentQuery, 'matricula', $request->query('matricula_token'));
        $assignments = $this->paginateAcademic($assignmentQuery->orderByDesc('id'), $request,
            ['*'], 'asignaciones_page', 'asignaciones_per_page');
        $enrollments = $this->paginateAcademic($enrollmentQuery->orderByDesc('id'), $request,
            ['*'], 'matriculas_page', 'matriculas_per_page');
        $availableStudents = $manage ? User::role('estudiante')->where('status', 'active')
            ->when($yearId, fn ($q) => $q->whereNotIn('id', Matricula::where('ano_lectivo_id', $yearId)
                ->where('estado', 'activa')->select('estudiante_id')))
            ->when($request->filled('student_search'), fn ($q) => $q->where('name', 'like', '%'.trim((string) $request->query('student_search')).'%'))
            ->orderBy('name')->limit(50)->get(['id', 'name']) : collect();
        $groupOptions = ($manage || $teacher) ? $this->boundedCatalog(Grupo::with('grado')->where('estado', 'activo')
            ->when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->when(! $manage, fn ($q) => $q->whereIn('id', AsignacionDocente::where('docente_id', $user->id)->select('grupo_id'))),
            $request, 'group_search', [$groupId, $request->integer('selected_grupo_id')]) : collect();
        $subjectOptions = ($manage || $teacher) ? $this->boundedCatalog(Materia::where('estado', 'activo')
            ->when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->when(! $manage, fn ($q) => $q->whereIn('id', AsignacionDocente::where('docente_id', $user->id)->select('materia_id'))),
            $request, 'materia_search', [$request->integer('materia_id'), $request->integer('selected_materia_id')]) : collect();

        if ($request->boolean('opaque')) {
            abort_if($request->filled('asignacion_token') && ! $selectedAssignment, 404);
            abort_if($request->filled('matricula_token') && ! $selectedEnrollment, 404);

            return response()->json(['data' => [
                'can_manage' => $manage, 'can_configure' => $user->can('academico.configurar'),
                'can_view_reports' => $manage || $user->can('notas.ver_propias'),
                'anos' => AnoLectivo::orderByDesc('fecha_inicio')->get(['id', 'nombre', 'estado'])->map(PublicEval::year(...)),
                'periodos' => Periodo::when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
                    ->orderBy('orden')->get()->map(PublicEval::period(...)),
                'asignaciones' => $assignments->getCollection()->map(PublicEval::assignment(...)),
                'matriculas' => $enrollments->getCollection()->map(PublicEval::enrollment(...)),
                'pagination' => ['asignaciones' => $this->paginationMeta($assignments), 'matriculas' => $this->paginationMeta($enrollments)],
                'selected_asignacion' => $selectedAssignment ? PublicEval::assignment($selectedAssignment) : null,
                'selected_matricula' => $selectedEnrollment ? PublicEval::enrollment($selectedEnrollment) : null,
                'grupos' => $groupOptions->map(PublicEval::group(...)),
                'materias' => $subjectOptions->map(PublicEval::subject(...)),
                'estudiantes' => $availableStudents->map(PublicEval::user(...)),
                'estudiantes_disponibles' => $availableStudents->map(PublicEval::user(...)),
            ]]);
        }

        return response()->json(['data' => [
            'can_manage' => $manage, 'can_configure' => $user->can('academico.configurar'),
            'can_view_reports' => $manage || $user->can('notas.ver_propias'),
            'anos' => AnoLectivo::orderByDesc('fecha_inicio')->get(['id', 'nombre', 'estado']),
            'periodos' => Periodo::when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))->orderBy('orden')->get()
                ->map(fn (Periodo $period) => [...$period->toArray(), 'url_token' => OpaqueUrlToken::for('periodo', $period->id)]),
            'asignaciones' => $assignments->getCollection()
                ->map(fn (AsignacionDocente $assignment) => [...$assignment->toArray(), 'url_token' => OpaqueUrlToken::for('asignacion-docente', $assignment->id)]),
            'matriculas' => $enrollments->getCollection()
                ->map(fn (Matricula $enrollment) => [...$enrollment->toArray(), 'url_token' => OpaqueUrlToken::for('matricula', $enrollment->id)]),
            'pagination' => ['asignaciones' => $this->paginationMeta($assignments), 'matriculas' => $this->paginationMeta($enrollments)],
            'selected_asignacion' => $selectedAssignment ? [...$selectedAssignment->toArray(), 'url_token' => OpaqueUrlToken::for('asignacion-docente', $selectedAssignment->id)] : null,
            'selected_matricula' => $selectedEnrollment ? [...$selectedEnrollment->toArray(), 'url_token' => OpaqueUrlToken::for('matricula', $selectedEnrollment->id)] : null,
            'grupos' => $groupOptions,
            'materias' => $subjectOptions,
            'estudiantes' => $availableStudents,
            'estudiantes_disponibles' => $availableStudents,
        ]]);
    }

    private function boundedCatalog(\Illuminate\Database\Eloquent\Builder $query, Request $request,
        string $searchParam, array $selectedIds): \Illuminate\Database\Eloquent\Collection
    {
        $base = clone $query;
        $term = trim((string) $request->query($searchParam, ''));
        $items = $query->when($term !== '', fn ($q) => $q->where('nombre', 'like', '%'.$term.'%'))
            ->orderBy('nombre')->orderBy('id')->limit(50)->get();
        foreach (array_unique(array_filter($selectedIds)) as $id) {
            if (! $items->contains('id', $id) && ($selected = (clone $base)->find($id))) {
                $items->push($selected);
            }
        }

        return $items;
    }

    private function selectedByToken(\Illuminate\Database\Eloquent\Builder $query, string $resource, mixed $token): ?\Illuminate\Database\Eloquent\Model
    {
        return OpaqueUrlToken::find($resource, $token, clone $query);
    }

    public function matricular(Request $request): JsonResponse
    {
        abort_unless($this->book->canManageEnrollment($request->user()), 403);
        $data = $request->validate(['grupo_id' => ['required', 'integer'], 'estudiante_id' => ['required', 'integer']]);
        $enrollment = DB::transaction(function () use ($data, $request) {
            $group = Grupo::findOrFail($data['grupo_id']);
            $year = AnoLectivo::lockForUpdate()->findOrFail($group->ano_lectivo_id);
            $group = Grupo::lockForUpdate()->findOrFail($group->id);
            $student = User::findOrFail($data['estudiante_id']);
            abort_if($year->estaCerrado() || ! $group->estaActivo(), 422, 'El grupo o el año no admite matrículas.');
            abort_unless($student->status === 'active' && $student->hasRole('estudiante'), 422, 'Selecciona una cuenta activa de estudiante.');
            abort_if(Matricula::where('estudiante_id', $student->id)->where('ano_lectivo_id', $year->id)->exists(), 422, 'El estudiante ya tiene matrícula en este año.');
            abort_if($group->cupo_maximo !== null && Matricula::where('grupo_id', $group->id)->where('estado', 'activa')->count() >= $group->cupo_maximo, 422, 'El grupo alcanzó su cupo máximo.');
            $enrollment = Matricula::create([...$data, 'ano_lectivo_id' => $year->id, 'estado' => 'activa']);
            AuditLogger::tenant($request->user(), 'CREATE', 'matricula', (string) $enrollment->id, null, $enrollment->toArray());

            return $enrollment;
        });

        return response()->json(['data' => $request->boolean('opaque')
            ? PublicEval::enrollment($enrollment->load(['estudiante:id,name', 'grupo.grado'])) : $enrollment], 201);
    }

    public function planilla(Request $request, int $asignacion, int $periodo): JsonResponse
    {
        $assignment = AsignacionDocente::findOrFail($asignacion);
        $this->book->authorizeAssignment($request->user(), $assignment);
        $period = Periodo::where('ano_lectivo_id', $assignment->ano_lectivo_id)->findOrFail($periodo);
        $year = AnoLectivo::findOrFail($assignment->ano_lectivo_id);
        $config = app(SieeConfiguration::class)->resolve($year);
        $components = ComponenteEvaluacion::with('actividades')->where('asignacion_id', $asignacion)->where('periodo_id', $periodo)->get();
        $enrollmentPage = $this->paginateAcademic(Matricula::with('estudiante:id,name')
            ->where('grupo_id', $assignment->grupo_id)->where('estado', 'activa')
            ->when($request->filled('search'), fn ($q) => $q->whereHas('estudiante', fn ($student) =>
                $student->where('name', 'like', '%'.trim((string) $request->query('search')).'%')))
            ->orderBy('id'), $request);
        $enrollments = $enrollmentPage->getCollection();
        $grades = Calificacion::whereIn('matricula_id', $enrollments->pluck('id'))
            ->whereIn('actividad_id', $components->flatMap(fn ($c) => $c->actividades->pluck('id')))->get();
        $gradesByEnrollment = $grades->groupBy('matricula_id');
        $results = $enrollments->mapWithKeys(fn (Matricula $enrollment) => [$enrollment->id =>
            $this->book->resultFromLoaded($components,
                $gradesByEnrollment->get($enrollment->id, collect())->keyBy('actividad_id'), $config)]);

        if ($request->boolean('opaque')) {
            return response()->json(['data' => [
                'editable' => $year->estado === 'en_curso' && $period->estado === 'abierto',
                'configuracion' => PublicEval::config($config),
                'componentes' => $components->map(PublicEval::component(...)),
                'matriculas' => $enrollments->map(PublicEval::enrollment(...)),
                'pagination' => ['matriculas' => $this->paginationMeta($enrollmentPage)],
                'calificaciones' => $grades->map(PublicEval::grade(...)),
                'resultados' => $enrollments->map(fn (Matricula $enrollment) => [
                    'matricula_token' => PublicEval::token('matricula', $enrollment->id),
                    ...PublicEval::result($results->get($enrollment->id)),
                ]),
            ]]);
        }

        return response()->json(['data' => [
            'editable' => $year->estado === 'en_curso' && $period->estado === 'abierto', 'configuracion' => $config,
            'componentes' => $components->map(fn (ComponenteEvaluacion $component) => [
                ...$component->toArray(),
                'url_token' => OpaqueUrlToken::for('componente-evaluacion', $component->id),
                'actividades' => $component->actividades->map(fn (ActividadEvaluacion $activity) => [
                    ...$activity->toArray(),
                    'url_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id),
                ]),
            ]),
            'matriculas' => $enrollments->map(fn (Matricula $enrollment) => [
                ...$enrollment->toArray(), 'url_token' => OpaqueUrlToken::for('matricula', $enrollment->id),
            ]),
            'pagination' => ['matriculas' => $this->paginationMeta($enrollmentPage)],
            'calificaciones' => $grades
                ->map(fn (Calificacion $grade) => [...$grade->toArray(),
                    'matricula_token' => OpaqueUrlToken::for('matricula', $grade->matricula_id),
                    'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $grade->actividad_id),
                ]),
            'resultados' => $enrollments->map(fn (Matricula $enrollment) => [
                'matricula_id' => $enrollment->id,
                'matricula_token' => OpaqueUrlToken::for('matricula', $enrollment->id),
                ...$results->get($enrollment->id),
            ]),
        ]]);
    }

    public function componente(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'asignacion_id' => ['required', 'integer'], 'periodo_id' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:120'], 'peso' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'modo' => ['required', Rule::in(['SIMPLE_AVERAGE', 'WEIGHTED_AVERAGE'])],
        ]);
        $assignment = AsignacionDocente::findOrFail($data['asignacion_id']);
        $this->book->authorizeAssignment($request->user(), $assignment, write: true);
        $component = DB::transaction(function () use ($data, $assignment, $request, $id) {
            $this->book->writable($assignment, $data['periodo_id']);
            $component = $id ? ComponenteEvaluacion::where('asignacion_id', $assignment->id)->where('periodo_id', $data['periodo_id'])->findOrFail($id) : new ComponenteEvaluacion;
            abort_if(ComponenteEvaluacion::where('asignacion_id', $assignment->id)->where('periodo_id', $data['periodo_id'])->where('nombre', $data['nombre'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, 'Ya existe un componente con ese nombre.');
            $previous = $component->exists ? $component->toArray() : null;
            $component->fill($data)->save();
            AuditLogger::tenant($request->user(), $previous ? 'UPDATE' : 'CREATE', 'componente_evaluacion', (string) $component->id, $previous, $component->toArray());

            return $component;
        });

        if ($request->boolean('opaque')) {
            return response()->json(['data' => PublicEval::component($component)], $id ? 200 : 201);
        }

        return response()->json(['data' => [...$component->toArray(),
            'url_token' => OpaqueUrlToken::for('componente-evaluacion', $component->id)]], $id ? 200 : 201);
    }

    public function actividad(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'componente_id' => ['required', 'integer'], 'nombre' => ['required', 'string', 'max:160'],
            'fecha' => ['required', 'date_format:Y-m-d'], 'peso' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
        ]);
        $component = ComponenteEvaluacion::findOrFail($data['componente_id']);
        $this->book->authorizeAssignment($request->user(), $component->asignacion, write: true);
        $activity = DB::transaction(function () use ($data, $component, $request, $id) {
            $period = $this->book->writable($component->asignacion, $component->periodo_id);
            abort_if($data['fecha'] < $period->fecha_inicio->toDateString() || $data['fecha'] > $period->fecha_fin->toDateString(), 422, 'La actividad debe estar dentro de las fechas del período.');
            $activity = $id ? ActividadEvaluacion::where('componente_id', $component->id)->findOrFail($id) : new ActividadEvaluacion;
            $previous = $activity->exists ? $activity->toArray() : null;
            $activity->fill($data)->save();
            AuditLogger::tenant($request->user(), $previous ? 'UPDATE' : 'CREATE', 'actividad_evaluacion', (string) $activity->id, $previous, $activity->toArray());

            return $activity;
        });

        if ($request->boolean('opaque')) {
            return response()->json(['data' => PublicEval::activity($activity)], $id ? 200 : 201);
        }

        return response()->json(['data' => [...$activity->toArray(),
            'url_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id)]], $id ? 200 : 201);
    }

    public function notas(Request $request, int $asignacion, int $periodo): JsonResponse
    {
        $data = $request->validate([
            'notas' => ['required', 'array', 'min:1', 'max:1000'],
            'notas.*.actividad_id' => ['required', 'integer'], 'notas.*.matricula_id' => ['required', 'integer'],
            'notas.*.valor' => ['present', 'nullable', 'numeric', 'decimal:0,8'], 'notas.*.version' => ['required', 'integer', 'min:0'],
            'notas.*.observacion' => ['nullable', 'string', 'max:1000'], 'notas.*.motivo' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $this->book->saveGrades($request->user(), AsignacionDocente::findOrFail($asignacion), $periodo, $data['notas']);

        return $this->planilla($request, $asignacion, $periodo);
    }

    public function boletin(Request $request, int $id): JsonResponse
    {
        $enrollment = Matricula::with(['estudiante:id,name', 'grupo.grado'])->findOrFail($id);
        // Un docente no obtiene las notas de otras asignaturas; usa su planilla.
        abort_unless($this->book->manages($request->user()) || ($enrollment->estudiante_id == $request->user()->id
            && $request->user()->can('notas.ver_propias')), 403);
        $report = $this->book->report($enrollment);
        AuditLogger::tenant($request->user(), 'READ', 'boletin_preliminar', (string) $id);

        return response()->json(['data' => $request->boolean('opaque') ? PublicEval::report($report) : $report]);
    }
}
