<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\SesionHorario;
use App\Models\User;
use App\Services\HorarioService;
use App\Services\AsignacionHorarioService;
use App\Services\AcademicYearSelection;
use App\Support\Audit\AuditLogger;
use App\Support\AcademicOpaqueRecord;
use App\Support\OpaqueUrlToken;
use App\Support\ScheduleOpaquePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HorarioController extends Controller
{
    use PaginatesRequests;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $manage = $user->can('academico.plan_estudios.gestionar');
        $student = $user->hasRole('estudiante');
        abort_unless($manage || $user->hasRole('docente') || $student, 403);
        $filters = $request->validate([
            'ano_lectivo_id' => ['nullable', 'integer', 'exists:anos_lectivos,id'],
            'grupo_id' => ['nullable', 'integer', 'exists:grupos,id'],
            'materia_id' => ['nullable', 'integer', 'exists:materias,id'],
            'docente_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'vista' => ['nullable', Rule::in(['horarios'])],
        ]);
        $yearId = isset($filters['ano_lectivo_id']) ? (int) $filters['ano_lectivo_id'] : null;
        $groupId = isset($filters['grupo_id']) ? (int) $filters['grupo_id'] : null;
        $teacherId = isset($filters['docente_id']) ? (int) $filters['docente_id'] : null;
        $scheduleView = ($filters['vista'] ?? null) === 'horarios';

        if ($groupId !== null && $yearId !== null) {
            abort_unless(Grupo::whereKey($groupId)->where('ano_lectivo_id', $yearId)->exists(), 422,
                'El grupo no pertenece al año lectivo seleccionado.');
        }

        // El gestor necesita elegir un grupo en la vista de horarios. Así no se
        // consultan ni serializan cientos de clases antes de escoger el filtro.
        $assignmentPage = null;
        if ($scheduleView && $manage && $groupId === null) {
            $assignments = collect();
            $sessions = collect();
        } else {
            $assignmentQuery = AsignacionDocente::with(['docente:id,name', 'materia', 'grupo.grado', 'grupo.sede'])
                ->when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
                ->when($groupId, fn ($q) => $q->where('grupo_id', $groupId))
                ->when(isset($filters['materia_id']), fn ($q) => $q->where('materia_id', $filters['materia_id']))
                ->when($teacherId, fn ($q) => $q->where('docente_id', $teacherId))
                ->when(! empty($filters['search']), function ($q) use ($filters) {
                    $term = '%'.trim($filters['search']).'%';
                    $q->where(fn ($match) => $match
                        ->whereHas('materia', fn ($subject) => $subject->where('nombre', 'like', $term))
                        ->orWhereHas('grupo', fn ($group) => $group->where('nombre', 'like', $term))
                        ->orWhereHas('docente', fn ($teacher) => $teacher->where('name', 'like', $term)));
                })
                ->when(! $manage, fn ($q) => $student
                    ? $q->whereIn('grupo_id', Matricula::where('estudiante_id', $user->id)->where('estado', 'activa')->select('grupo_id'))
                    : $q->where('docente_id', $user->id));
            if ($scheduleView) {
                $assignments = $assignmentQuery->orderBy('id')->get();
            } else {
                $assignmentPage = $this->paginateAcademic($assignmentQuery->orderBy('id'), $request);
                $assignments = $assignmentPage->getCollection();
            }
            $sessions = SesionHorario::with(['grupo.grado.nivel', 'materia', 'docente:id,name', 'bloque', 'espacio'])
                ->when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
                ->when($groupId, fn ($q) => $q->where('grupo_id', $groupId))
                ->when($teacherId, fn ($q) => $q->where('docente_id', $teacherId))
                ->when(isset($filters['materia_id']), fn ($q) => $q->where('materia_id', $filters['materia_id']))
                ->when(! $scheduleView, fn ($q) => $q->whereIn('asignacion_id', $assignments->pluck('id')))
                ->when(! $manage, fn ($q) => $student
                    ? $q->whereIn('grupo_id', Matricula::where('estudiante_id', $user->id)->where('estado', 'activa')->select('grupo_id'))
                    : $q->where('docente_id', $user->id))->get();
        }
        $groupIds = $assignments->pluck('grupo_id')->merge($sessions->pluck('grupo_id'))->unique();
        $subjectIds = $assignments->pluck('materia_id')->merge($sessions->pluck('materia_id'))->unique();
        $sessionCount = SesionHorario::query()
            ->when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->when($groupId, fn ($q) => $q->where('grupo_id', $groupId))
            ->when($teacherId, fn ($q) => $q->where('docente_id', $teacherId))
            ->when(isset($filters['materia_id']), fn ($q) => $q->where('materia_id', $filters['materia_id']))
            ->when(! $manage, fn ($q) => $student
                ? $q->whereIn('grupo_id', Matricula::where('estudiante_id', $user->id)->where('estado', 'activa')->select('grupo_id'))
                : $q->where('docente_id', $user->id))->count();
        $subjectCount = $manage && $groupId === null && $teacherId === null && ! isset($filters['materia_id'])
            ? Materia::when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))->count()
            : Materia::query()
                ->when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
                ->where(function (Builder $q) use ($yearId, $groupId, $teacherId, $filters, $manage, $student, $user) {
                    $assignmentsWithSubject = AsignacionDocente::query()->select('materia_id')
                        ->when($yearId, fn ($query) => $query->where('ano_lectivo_id', $yearId))
                        ->when($groupId, fn ($query) => $query->where('grupo_id', $groupId))
                        ->when($teacherId, fn ($query) => $query->where('docente_id', $teacherId))
                        ->when(isset($filters['materia_id']), fn ($query) => $query->where('materia_id', $filters['materia_id']))
                        ->when(! $manage, fn ($query) => $student
                            ? $query->whereIn('grupo_id', Matricula::where('estudiante_id', $user->id)->where('estado', 'activa')->select('grupo_id'))
                            : $query->where('docente_id', $user->id));
                    $sessionsWithSubject = SesionHorario::query()->select('materia_id')
                        ->when($yearId, fn ($query) => $query->where('ano_lectivo_id', $yearId))
                        ->when($groupId, fn ($query) => $query->where('grupo_id', $groupId))
                        ->when($teacherId, fn ($query) => $query->where('docente_id', $teacherId))
                        ->when(isset($filters['materia_id']), fn ($query) => $query->where('materia_id', $filters['materia_id']))
                        ->when(! $manage, fn ($query) => $student
                            ? $query->whereIn('grupo_id', Matricula::where('estudiante_id', $user->id)->where('estado', 'activa')->select('grupo_id'))
                            : $query->where('docente_id', $user->id));
                    $q->whereIn('id', $assignmentsWithSubject)->orWhereIn('id', $sessionsWithSubject);
                })->count();
        $areas = $manage ? $this->boundedOptions(Area::when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId)),
            $request, 'area_search', [$request->integer('selected_area_id')]) : collect();
        $subjects = $this->boundedOptions(Materia::when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->when(! $manage, fn ($q) => $q->whereIn('id', $subjectIds)), $request, 'materia_search',
            [$request->integer('materia_id'), $request->integer('selected_materia_id')]);
        $groups = $this->boundedOptions(Grupo::with(['grado.nivel', 'sede', 'jornada'])
            ->when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->when(! $manage, fn ($q) => $q->whereIn('id', $groupIds)), $request, 'grupo_search',
            [$groupId, $request->integer('selected_grupo_id')], 'grupo', 'grupo_token');
        $teachers = $manage ? $this->boundedOptions(User::role('docente')->where('status', 'active'),
            $request, 'docente_search', [$request->integer('docente_id'), $request->integer('selected_docente_id')],
            $request->boolean('opaque') ? 'usuario' : 'docente', 'docente_token') : collect();
        $blocks = $this->boundedOptions(BloqueHorario::when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->where('estado', 'activo')->where('es_descanso', false), $request, 'bloque_search',
            [$request->integer('selected_bloque_id')], null, null, 'hora_inicio');
        $spaces = $this->boundedOptions(EspacioFisico::when($yearId, fn ($q) => $q->where('ano_lectivo_id', $yearId))
            ->where('estado', EspacioFisico::ESTADO_DISPONIBLE), $request, 'espacio_search',
            [$request->integer('selected_espacio_id')], 'espacio-fisico', 'espacio_token');

        if ($request->boolean('opaque')) {
            return response()->json(['data' => [
                'can_manage' => $manage,
                'counts' => ['sesiones' => $sessionCount, 'materias' => $subjectCount],
                'anos' => AnoLectivo::orderByDesc('fecha_inicio')->get()->map(fn (AnoLectivo $year) => AcademicOpaqueRecord::present($year, 'ano-lectivo')),
                'areas' => $areas->map(fn (Area $area) => AcademicOpaqueRecord::present($area, 'area')),
                'materias' => $subjects->map(fn (Materia $subject) => AcademicOpaqueRecord::present($subject, 'materia')),
                'grupos' => $groups->map(fn (Grupo $group) => AcademicOpaqueRecord::present($group, 'grupo')),
                'docentes' => $teachers->map(ScheduleOpaquePresenter::user(...)),
                'bloques' => $blocks->map(fn (BloqueHorario $block) => AcademicOpaqueRecord::present($block, 'bloque-horario')),
                'espacios' => $spaces->map(fn (EspacioFisico $space) => AcademicOpaqueRecord::present($space, 'espacio-fisico')),
                'asignaciones' => $assignments->map(ScheduleOpaquePresenter::assignment(...)),
                'pagination' => ['asignaciones' => $assignmentPage
                    ? $this->paginationMeta($assignmentPage)
                    : ['current_page' => 1, 'last_page' => 1, 'per_page' => $this->resolvePerPage($request),
                        'total' => $assignments->count(), 'from' => $assignments->isEmpty() ? null : 1,
                        'to' => $assignments->isEmpty() ? null : $assignments->count()]],
                'sesiones' => $sessions->map(ScheduleOpaquePresenter::session(...)),
            ]]);
        }

        return response()->json(['data' => [
            'can_manage' => $manage,
            'counts' => ['sesiones' => $sessionCount, 'materias' => $subjectCount],
            'anos' => AnoLectivo::orderByDesc('fecha_inicio')->get(),
            'areas' => $areas,
            'materias' => $subjects,
            'grupos' => $groups
                ->map(fn (Grupo $group) => [...$group->toArray(), 'url_token' => OpaqueUrlToken::for('grupo', $group->id)]),
            'docentes' => $teachers->map(fn (User $teacher) => ['id' => $teacher->id, 'name' => $teacher->name,
                'url_token' => OpaqueUrlToken::for('docente', $teacher->id)]),
            'bloques' => $blocks,
            'espacios' => $spaces
                ->map(fn (EspacioFisico $space) => [...$space->toArray(), 'url_token' => OpaqueUrlToken::for('espacio-fisico', $space->id)]),
            'asignaciones' => $assignments,
            'pagination' => ['asignaciones' => $assignmentPage
                ? $this->paginationMeta($assignmentPage)
                : ['current_page' => 1, 'last_page' => 1, 'per_page' => $this->resolvePerPage($request),
                    'total' => $assignments->count(), 'from' => $assignments->isEmpty() ? null : 1,
                    'to' => $assignments->isEmpty() ? null : $assignments->count()]],
            'sesiones' => $sessions,
        ]]);
    }

    /** Mantiene los selectores livianos sin perder una opción ya elegida. */
    private function boundedOptions(Builder $query, Request $request, string $searchParam,
        array $selectedIds = [], ?string $resource = null, ?string $tokenParam = null,
        string $sort = 'nombre'): Collection
    {
        $base = clone $query;
        $name = $query->getModel() instanceof User ? 'name' : 'nombre';
        $term = trim((string) $request->query($searchParam, ''));
        $items = $query->when($term !== '', fn ($q) => $q->where($name, 'like', '%'.$term.'%'))
            ->orderBy($sort === 'nombre' ? $name : $sort)->orderBy('id')->limit(50)->get();
        if ($resource && $tokenParam && is_string($request->query($tokenParam))) {
            $token = $request->query($tokenParam);
            if (preg_match('/^[A-Za-z0-9_-]{24}$/', $token)) {
                foreach ((clone $base)->select('id')->cursor() as $candidate) {
                    if (hash_equals(OpaqueUrlToken::for($resource, $candidate->id), $token)) {
                        $selectedIds[] = $candidate->id;
                        break;
                    }
                }
            }
        }
        foreach (array_unique(array_filter($selectedIds)) as $id) {
            if (! $items->contains('id', $id) && ($selected = (clone $base)->find($id))) {
                $items->push($selected);
            }
        }

        return $items;
    }

    public function asignar(Request $request, AsignacionHorarioService $service): JsonResponse
    {
        $data = $request->validate([
            'ano_lectivo_id' => ['required', 'integer'], 'docente_id' => ['nullable', 'integer', 'exists:users,id'],
            'materia_id' => ['required', 'integer'], 'grupo_id' => ['required', 'integer'],
        ]);
        $wasCreated = false;
        $assignment = DB::transaction(function () use ($data, $request, $service, &$wasCreated) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($data['ano_lectivo_id']);
            abort_if($year->estaCerrado(), 422, 'El año lectivo está cerrado.');
            $group = Grupo::with('grado')->findOrFail($data['grupo_id']);
            $subject = Materia::findOrFail($data['materia_id']);
            AcademicYearSelection::assertSame($subject->ano_lectivo_id, $year);
            AcademicYearSelection::assertSame($group->grado?->ano_lectivo_id, $year);
            abort_unless($group->ano_lectivo_id == $year->id && $group->estaActivo(), 422, 'El grupo no pertenece al año o no está activo.');
            abort_unless($subject->estado === 'activo' && ($subject->nivel_id === null || $subject->nivel_id == $group->grado->nivel_id), 422, 'La asignatura no corresponde al nivel del grupo.');
            $wasCreated = ! AsignacionDocente::where('ano_lectivo_id', $year->id)
                ->where('grupo_id', $group->id)->where('materia_id', $subject->id)->exists();
            $assignment = $service->guardar($group, $subject, $data['docente_id'] ?? null, $request->user());

            return $assignment;
        });

        $assignment->load(['docente:id,name', 'materia', 'grupo.grado']);

        return response()->json(['data' => $request->boolean('opaque')
            ? ScheduleOpaquePresenter::assignment($assignment) : $assignment], $wasCreated ? 201 : 200);
    }

    public function editarAsignacion(Request $request, int $id, AsignacionHorarioService $service, HorarioService $horarios): JsonResponse
    {
        $data = $request->validate([
            'docente_id' => ['nullable', 'integer', 'exists:users,id'],
            'materia_id' => ['required', 'integer', 'exists:materias,id'],
            'grupo_id' => ['required', 'integer', 'exists:grupos,id'],
        ]);
        $assignment = DB::transaction(function () use ($id, $data, $request, $service, $horarios) {
            $assignment = AsignacionDocente::lockForUpdate()->findOrFail($id);
            $year = AnoLectivo::lockForUpdate()->findOrFail($assignment->ano_lectivo_id);
            abort_if($year->estaCerrado(), 422, 'El año lectivo está cerrado.');
            $group = Grupo::with(['grado', 'jornada'])->findOrFail($data['grupo_id']);
            $subject = Materia::findOrFail($data['materia_id']);
            AcademicYearSelection::assertSame($group->ano_lectivo_id, $year);
            AcademicYearSelection::assertSame($group->grado?->ano_lectivo_id, $year);
            AcademicYearSelection::assertSame($subject->ano_lectivo_id, $year);
            abort_unless($group->estaActivo() && $subject->estado === 'activo'
                && ($subject->nivel_id === null || $subject->nivel_id == $group->grado?->nivel_id), 422,
                'Selecciona un grupo y una asignatura activos del mismo año lectivo y nivel.');

            $changed = $assignment->grupo_id != $group->id || $assignment->materia_id != $subject->id;
            if ($changed) {
                abort_if(ComponenteEvaluacion::where('asignacion_id', $id)->exists(), 422,
                    'La asignación tiene evaluaciones asociadas. No se puede cambiar su grupo o asignatura.');
                abort_if(AsignacionDocente::where('ano_lectivo_id', $year->id)->where('grupo_id', $group->id)
                    ->where('materia_id', $subject->id)->whereKeyNot($id)->exists(), 422,
                    'Ya existe una asignación para esa asignatura y grupo. Edítala directamente.');
            }
            $classes = SesionHorario::where('asignacion_id', $id)->get();
            if ($changed) {
                $previous = $assignment->toArray();
                $assignment->update(['grupo_id' => $group->id, 'materia_id' => $subject->id]);
                AuditLogger::tenant($request->user(), 'UPDATE', 'asignacion_docente', (string) $id, $previous, $assignment->toArray());
            }
            if ($classes->isEmpty()) {
                return $service->guardar($group, $subject, $data['docente_id'] ?? null, $request->user());
            }
            foreach ($classes as $class) {
                $horarios->guardar([
                    'grupo_id' => $group->id, 'materia_id' => $subject->id,
                    'docente_id' => $data['docente_id'] ?? null,
                    'dia' => $class->dia,
                    'bloque_horario_id' => $class->bloque_horario_id,
                    'hora_inicio' => $class->bloque_horario_id ? null : substr($class->hora_inicio, 0, 5),
                    'hora_fin' => $class->bloque_horario_id ? null : substr($class->hora_fin, 0, 5),
                    'espacio_fisico_id' => $class->espacio_fisico_id,
                ], $request->user(), $class);
            }
            return $assignment->fresh();
        });

        $assignment->load(['docente:id,name', 'materia', 'grupo.grado']);

        return response()->json(['data' => $request->boolean('opaque')
            ? ScheduleOpaquePresenter::assignment($assignment) : $assignment]);
    }

    public function desasignar(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($id, $request) {
            $assignment = AsignacionDocente::findOrFail($id);
            $year = AnoLectivo::lockForUpdate()->findOrFail($assignment->ano_lectivo_id);
            abort_if($year->estaCerrado(), 422, 'El año lectivo está cerrado.');
            abort_if(ComponenteEvaluacion::where('asignacion_id', $id)->exists(), 422, 'La asignación tiene evaluación asociada y debe conservarse.');
            abort_if(SesionHorario::where('grupo_id', $assignment->grupo_id)
                ->where('materia_id', $assignment->materia_id)->exists(), 422, 'La asignación tiene clases programadas. Elimínalas antes de borrar la asignación.');
            AuditLogger::tenant($request->user(), 'DELETE', 'asignacion_docente', (string) $id, $assignment->toArray());
            $assignment->delete();
        });

        return response()->json(['data' => null]);
    }

    public function guardar(Request $request, HorarioService $service, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'asignacion_id' => ['nullable', 'integer', 'exists:asignaciones_docentes,id'],
            'grupo_id' => ['nullable', 'integer', 'exists:grupos,id'],
            'materia_id' => ['nullable', 'integer', 'exists:materias,id'],
            'docente_id' => ['nullable', 'integer', 'exists:users,id'],
            'dia' => ['required', Rule::in(SesionHorario::DIAS)],
            'bloque_horario_id' => ['nullable', 'integer', 'exists:bloques_horarios,id'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fin' => ['nullable', 'date_format:H:i'],
            'espacio_fisico_id' => ['nullable', 'integer'],
        ]);
        $session = $id ? SesionHorario::findOrFail($id) : null;
        $saved = $service->guardar($data, $request->user(), $session);

        return response()->json(['data' => $request->boolean('opaque')
            ? ScheduleOpaquePresenter::session($saved) : $saved], $id ? 200 : 201);
    }

    public function eliminar(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($id, $request) {
            $session = SesionHorario::with('grupo')->findOrFail($id);
            $year = AnoLectivo::lockForUpdate()->findOrFail($session->grupo->ano_lectivo_id);
            abort_if($year->estaCerrado(), 422, 'El año lectivo está cerrado.');
            AuditLogger::tenant($request->user(), 'DELETE', 'sesion_horario', (string) $id, $session->toArray());
            $session->delete();
        });

        return response()->json(['data' => null]);
    }
}
