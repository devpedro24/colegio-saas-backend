<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AulaRecurso;
use App\Models\User;
use App\Services\AcademicPlanAccess;
use App\Services\AttendanceNotifications;
use App\Services\AulaAccess;
use App\Services\AulaNotifications;
use App\Services\EventAccess;
use App\Services\TenantPlanAccess;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class NotificationUpdateController extends Controller
{
    public function index(Request $request, AulaAccess $access, AulaNotifications $notifications): JsonResponse
    {
        abort_unless(tenancy()->initialized, 404);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $user = $request->user();
        // Recheck current grants even if an authentication guard already loaded this account.
        $user->load('roles.permissions', 'permissions');
        $canAula = app(AcademicPlanAccess::class)->aula()
            && ($user->can('aula.ver_todas') || $user->can('aula.ver_asignadas') || $user->can('aula.ver_propias'));
        $canAttendance = in_array('asistencia', app(TenantPlanAccess::class)->features(), true)
            && ($user->can('asistencia.consultar_propia') || $user->can('asistencia.justificar_propia')
                || $user->can('asistencia.registrar_clases') || $user->can('asistencia.consultar_grupo'));
        $canEvents = app(TenantPlanAccess::class)->has('comunicacion_basica') && $user->status === User::STATUS_ACTIVE
            && ($user->roles->isNotEmpty() || $user->permissions->isNotEmpty());
        abort_unless($canAula || $canAttendance || $canEvents, 403);
        if ($canAula) {
            $notifications->reconcileDue();
        }
        // Filter BEFORE pagination/counting, including permissions revoked after delivery.
        $readable = [];
        $gradeReadable = [];
        $allowedAulas = [];
        $studentAulas = [];
        $years = AnoLectivo::pluck('nombre', 'id');
        $ids = $canAula ? DB::table('user_notification_updates')->where('user_id', $user->id)
            ->whereNotNull('recurso_id')->distinct()->pluck('recurso_id') : collect();
        foreach ($ids->chunk(200) as $chunk) {
            foreach (AulaRecurso::whereIn('id', $chunk)->with('seccion.aula.grupo.grado', 'seccion.aula.materia')->get() as $resource) {
                if (! $access->visible($resource)) {
                    continue;
                }
                $aula = $resource->seccion->aula;
                $allowed = $allowedAulas[$aula->id] ??= $notifications->eligible($user, $aula);
                $student = $studentAulas[$aula->id] ??= $notifications->enrolledStudent($user, $aula);
                if ($allowed) {
                    $readable[$resource->id] = $resource;
                    if ($student) {
                        $gradeReadable[] = $resource->id;
                    }
                }
            }
        }
        $attendance = collect();
        if ($canAttendance && $user->status === User::STATUS_ACTIVE && $user->hasRole('estudiante')) {
            $attendance = app(AttendanceNotifications::class)->readable($user)
                ->whereIn('mark.id', DB::table('user_notification_updates')->where('user_id', $user->id)
                    ->whereNotNull('asistencia_marca_id')->select('asistencia_marca_id'))
                ->get(['mark.id', 'mark.estado', 'class.fecha', 'class.hora_inicio', 'class.hora_fin',
                    'subject.nombre as subject', 'student_group.nombre as student_group', 'grade.nombre as grade', 'year.nombre as year'])->keyBy('id');
        }
        $activeMarks = $attendance->whereIn('estado', ['ausente', 'tarde', 'justificada'])->keys()->all();
        $events = $canEvents ? app(EventAccess::class)->visible($user)->withTrashed()
            ->whereIn('id', DB::table('user_notification_updates')->where('user_id', $user->id)
                ->whereNotNull('evento_id')->select('evento_id'))->with('grupos.grado')->get()->keyBy('id') : collect();
        $liveEvents = $events->filter(fn ($event) => ! $event->trashed())->keys()->all();
        $deletedEvents = $events->filter(fn ($event) => $event->trashed())->keys()->all();
        $records = DB::table('user_notification_updates')->where('user_id', $user->id)
            ->where(function ($query) use ($readable, $gradeReadable, $attendance, $activeMarks, $liveEvents, $deletedEvents) {
                $query->where(fn ($q) => $q->whereIn('recurso_id', array_keys($readable))
                    ->where(fn ($q) => $q->where('event', '!=', 'aula.grade.saved')->orWhereIn('recurso_id', $gradeReadable)))
                    ->orWhere(fn ($q) => $q->whereIn('asistencia_marca_id', $attendance->keys()->all())
                        ->where(fn ($q) => $q->whereNotIn('event', ['attendance.absent', 'attendance.late'])
                            ->orWhereIn('asistencia_marca_id', $activeMarks)))
                    ->orWhereIn('evento_id', $liveEvents)
                    ->orWhere(fn ($q) => $q->whereIn('evento_id', $deletedEvents)->where('event', 'calendar.event.deleted'));
            })
            ->orderByDesc('created_at')->orderByDesc('url_token')->paginate(20);

        return response()->json([
            'data' => $records->getCollection()->map(function ($row) use ($readable, $years, $attendance, $events) {
                if ($row->evento_id !== null) {
                    $event = $events[$row->evento_id];

                    return ['token' => $row->url_token, 'event' => $row->event, 'resource_title' => $event->titulo,
                        'subject' => '', 'group' => $event->grupos->map(fn ($group) => trim(($group->grado?->nombre ?? '').' / '.$group->nombre, ' /'))->join(', '),
                        'grade' => null, 'year' => null, 'institutional' => (bool) $event->institucional,
                        'event_date' => $event->fecha, 'event_time' => $event->hora_inicio ? substr($event->hora_inicio, 0, 5)
                            .($event->hora_fin ? '–'.substr($event->hora_fin, 0, 5) : '') : null,
                        'url' => '/comunicacion/eventos?evento='.OpaqueUrlToken::for('evento', $event->id),
                        'created_at' => Carbon::parse($row->created_at)->toISOString()];
                }
                if ($row->asistencia_marca_id !== null) {
                    $mark = $attendance[$row->asistencia_marca_id];

                    return ['token' => $row->url_token, 'event' => $row->event, 'resource_title' => '',
                        'subject' => $mark->subject, 'group' => $mark->student_group, 'grade' => $mark->grade, 'year' => $mark->year,
                        'attendance_date' => $mark->fecha, 'attendance_time' => substr($mark->hora_inicio, 0, 5).'–'.substr($mark->hora_fin, 0, 5),
                        'url' => '/academico/asistencia', 'created_at' => Carbon::parse($row->created_at)->toISOString()];
                }
                $resource = $readable[$row->recurso_id];
                $aula = $resource->seccion->aula;

                return ['token' => $row->url_token, 'event' => $row->event, 'resource_title' => $resource->titulo,
                    'subject' => $aula->materia->nombre, 'group' => $aula->grupo->nombre,
                    'grade' => $aula->grupo->grado?->nombre, 'year' => $years[$aula->ano_lectivo_id] ?? null,
                    'url' => '/evaluacion/aula/recursos/'.OpaqueUrlToken::for('aula-recurso', $resource->id),
                    'created_at' => Carbon::parse($row->created_at)->toISOString()];
            }),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total()],
        ])->header('Cache-Control', 'private, no-store');
    }
}
