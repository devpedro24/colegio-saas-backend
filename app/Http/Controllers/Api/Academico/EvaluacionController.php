<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Services\GradebookService;
use App\Services\SieeConfiguration;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EvaluacionController extends Controller
{
    public function __construct(private GradebookService $book) {}

    public function catalogo(Request $request): JsonResponse
    {
        $user = $request->user();
        $manage = $this->book->manages($user);
        $teacher = $user->hasRole('docente');
        abort_unless($manage || $teacher || $user->hasRole('estudiante'), 403);
        $assignments = AsignacionDocente::with(['materia:id,nombre', 'grupo.grado'])
            ->when(! $manage, fn ($q) => $q->where('docente_id', $teacher ? $user->id : -1))->get();
        $enrollments = Matricula::with(['estudiante:id,name', 'grupo.grado'])->when(! $manage, function ($q) use ($teacher, $user, $assignments) {
            return $teacher ? $q->whereIn('grupo_id', $assignments->pluck('grupo_id')) : $q->where('estudiante_id', $user->id);
        })->get();

        return response()->json(['data' => [
            'can_manage' => $manage, 'can_configure' => $user->can('academico.configurar'),
            'can_view_reports' => $manage || $user->hasRole('estudiante'),
            'anos' => AnoLectivo::orderByDesc('fecha_inicio')->get(['id', 'nombre', 'estado']),
            'periodos' => Periodo::orderBy('orden')->get()
                ->map(fn (Periodo $period) => [...$period->toArray(), 'url_token' => OpaqueUrlToken::for('periodo', $period->id)]),
            'asignaciones' => $assignments
                ->map(fn (AsignacionDocente $assignment) => [...$assignment->toArray(), 'url_token' => OpaqueUrlToken::for('asignacion-docente', $assignment->id)]),
            'matriculas' => $enrollments
                ->map(fn (Matricula $enrollment) => [...$enrollment->toArray(), 'url_token' => OpaqueUrlToken::for('matricula', $enrollment->id)]),
            'grupos' => $manage ? Grupo::with('grado')->where('estado', 'activo')->get() : [],
            'estudiantes' => $manage ? User::role('estudiante')->where('status', 'active')->get(['id', 'name']) : [],
        ]]);
    }

    public function matricular(Request $request): JsonResponse
    {
        abort_unless($this->book->manages($request->user()), 403);
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

        return response()->json(['data' => $enrollment], 201);
    }

    public function planilla(Request $request, int $asignacion, int $periodo): JsonResponse
    {
        $assignment = AsignacionDocente::findOrFail($asignacion);
        $this->book->authorizeAssignment($request->user(), $assignment);
        $period = Periodo::where('ano_lectivo_id', $assignment->ano_lectivo_id)->findOrFail($periodo);
        $year = AnoLectivo::findOrFail($assignment->ano_lectivo_id);
        $config = app(SieeConfiguration::class)->resolve($year);
        $components = ComponenteEvaluacion::with('actividades')->where('asignacion_id', $asignacion)->where('periodo_id', $periodo)->get();
        $enrollments = Matricula::with('estudiante:id,name')->where('grupo_id', $assignment->grupo_id)->where('estado', 'activa')->get();

        return response()->json(['data' => [
            'editable' => $year->estado === 'en_curso' && $period->estado === 'abierto', 'configuracion' => $config,
            'componentes' => $components, 'matriculas' => $enrollments,
            'calificaciones' => Calificacion::whereIn('matricula_id', $enrollments->pluck('id'))->whereIn('actividad_id', $components->flatMap(fn ($c) => $c->actividades->pluck('id')))->get(),
            'resultados' => $enrollments->map(fn ($enrollment) => ['matricula_id' => $enrollment->id, ...$this->book->subjectResult($assignment, $enrollment, $period, $config)]),
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
        $this->book->authorizeAssignment($request->user(), $assignment);
        $component = DB::transaction(function () use ($data, $assignment, $request, $id) {
            $this->book->writable($assignment, $data['periodo_id']);
            $component = $id ? ComponenteEvaluacion::where('asignacion_id', $assignment->id)->where('periodo_id', $data['periodo_id'])->findOrFail($id) : new ComponenteEvaluacion;
            abort_if(ComponenteEvaluacion::where('asignacion_id', $assignment->id)->where('periodo_id', $data['periodo_id'])->where('nombre', $data['nombre'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, 'Ya existe un componente con ese nombre.');
            $previous = $component->exists ? $component->toArray() : null;
            $component->fill($data)->save();
            AuditLogger::tenant($request->user(), $previous ? 'UPDATE' : 'CREATE', 'componente_evaluacion', (string) $component->id, $previous, $component->toArray());

            return $component;
        });

        return response()->json(['data' => $component], $id ? 200 : 201);
    }

    public function actividad(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'componente_id' => ['required', 'integer'], 'nombre' => ['required', 'string', 'max:160'],
            'fecha' => ['required', 'date_format:Y-m-d'], 'peso' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
        ]);
        $component = ComponenteEvaluacion::findOrFail($data['componente_id']);
        $this->book->authorizeAssignment($request->user(), $component->asignacion);
        $activity = DB::transaction(function () use ($data, $component, $request, $id) {
            $period = $this->book->writable($component->asignacion, $component->periodo_id);
            abort_if($data['fecha'] < $period->fecha_inicio->toDateString() || $data['fecha'] > $period->fecha_fin->toDateString(), 422, 'La actividad debe estar dentro de las fechas del período.');
            $activity = $id ? ActividadEvaluacion::where('componente_id', $component->id)->findOrFail($id) : new ActividadEvaluacion;
            $previous = $activity->exists ? $activity->toArray() : null;
            $activity->fill($data)->save();
            AuditLogger::tenant($request->user(), $previous ? 'UPDATE' : 'CREATE', 'actividad_evaluacion', (string) $activity->id, $previous, $activity->toArray());

            return $activity;
        });

        return response()->json(['data' => $activity], $id ? 200 : 201);
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
        abort_unless($this->book->manages($request->user()) || $enrollment->estudiante_id == $request->user()->id, 403);
        $report = $this->book->report($enrollment);
        AuditLogger::tenant($request->user(), 'READ', 'boletin_preliminar', (string) $id);

        return response()->json(['data' => $report]);
    }
}
