<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
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
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HorarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $manage = $user->can('academico.plan_estudios.gestionar');
        $student = $user->hasRole('estudiante');
        abort_unless($manage || $user->hasRole('docente') || $student, 403);
        $assignments = AsignacionDocente::with(['docente:id,name', 'materia', 'grupo.grado', 'grupo.sede'])
            ->when(! $manage, fn ($q) => $student
                ? $q->whereIn('grupo_id', Matricula::where('estudiante_id', $user->id)->where('estado', 'activa')->select('grupo_id'))
                : $q->where('docente_id', $user->id))->get();
        $sessions = SesionHorario::with(['grupo.grado.nivel', 'materia', 'docente:id,name', 'bloque', 'espacio'])
            ->when(! $manage, fn ($q) => $student
                ? $q->whereIn('grupo_id', Matricula::where('estudiante_id', $user->id)->where('estado', 'activa')->select('grupo_id'))
                : $q->where('docente_id', $user->id))->get();
        $groupIds = $assignments->pluck('grupo_id')->merge($sessions->pluck('grupo_id'))->unique();
        $subjectIds = $assignments->pluck('materia_id')->merge($sessions->pluck('materia_id'))->unique();

        return response()->json(['data' => [
            'can_manage' => $manage,
            'anos' => AnoLectivo::orderByDesc('fecha_inicio')->get(),
            'areas' => $manage ? Area::orderBy('nombre')->get() : [],
            'materias' => $manage ? Materia::orderBy('nombre')->get() : Materia::whereIn('id', $subjectIds)->get(),
            'grupos' => Grupo::with(['grado.nivel', 'sede', 'jornada'])->when(! $manage, fn ($q) => $q->whereIn('id', $groupIds))->get(),
            'docentes' => $manage ? User::role('docente')->where('status', 'active')->get(['id', 'name']) : [],
            'bloques' => BloqueHorario::where('estado', 'activo')->where('es_descanso', false)->orderBy('hora_inicio')->get(),
            'espacios' => EspacioFisico::where('estado', EspacioFisico::ESTADO_DISPONIBLE)->get(),
            'asignaciones' => $assignments,
            'sesiones' => $sessions,
        ]]);
    }

    public function asignar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ano_lectivo_id' => ['required', 'integer'], 'docente_id' => ['required', 'integer'],
            'materia_id' => ['required', 'integer'], 'grupo_id' => ['required', 'integer'],
        ]);
        $assignment = DB::transaction(function () use ($data, $request) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($data['ano_lectivo_id']);
            abort_if($year->estaCerrado(), 422, 'El año lectivo está cerrado.');
            $group = Grupo::with('grado')->findOrFail($data['grupo_id']);
            $subject = Materia::findOrFail($data['materia_id']);
            $teacher = User::findOrFail($data['docente_id']);
            abort_unless($teacher->status === 'active' && $teacher->hasRole('docente'), 422, 'Selecciona un docente activo del colegio.');
            abort_unless($group->ano_lectivo_id == $year->id && $group->estaActivo(), 422, 'El grupo no pertenece al año o no está activo.');
            abort_unless($subject->estado === 'activo' && ($subject->nivel_id === null || $subject->nivel_id == $group->grado->nivel_id), 422, 'La asignatura no corresponde al nivel del grupo.');
            abort_if(AsignacionDocente::where('grupo_id', $group->id)->where('materia_id', $subject->id)->exists(), 422, 'Ya hay un docente asignado a esta materia y grupo.');
            $assignment = AsignacionDocente::create($data);
            AuditLogger::tenant($request->user(), 'CREATE', 'asignacion_docente', (string) $assignment->id, null, $assignment->toArray());

            return $assignment;
        });

        return response()->json(['data' => $assignment], 201);
    }

    public function desasignar(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($id, $request) {
            $assignment = AsignacionDocente::findOrFail($id);
            $year = AnoLectivo::lockForUpdate()->findOrFail($assignment->ano_lectivo_id);
            abort_if($year->estaCerrado(), 422, 'El año lectivo está cerrado.');
            abort_if(ComponenteEvaluacion::where('asignacion_id', $id)->exists(), 422, 'La asignación tiene evaluación asociada y debe conservarse.');
            // Legacy sessions retain their own group, subject and teacher after
            // unlinking the academic assignment; the timetable is independent.
            foreach (SesionHorario::where('asignacion_id', $id)->get() as $session) {
                $previous = $session->toArray();
                $session->update(['asignacion_id' => null]);
                AuditLogger::tenant($request->user(), 'UPDATE', 'sesion_horario', (string) $session->id, $previous, $session->toArray());
            }
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
        return response()->json(['data' => $service->guardar($data, $request->user(), $session)], $id ? 200 : 201);
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
