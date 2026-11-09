<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AsistenciaSolicitud;
use App\Models\Academico\Matricula;
use App\Models\User;
use App\Support\Realtime\RealtimeChanges;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Private attendance updates, committed atomically with the attendance action. */
class AttendanceNotifications
{
    public function markChanged(int $markId, string $status, int $version): void
    {
        if (! in_array($status, ['ausente', 'tarde'], true)) {
            return;
        }
        $mark = DB::table('asistencia_marcas')->where('id', $markId)->first();
        $this->deliver($markId, $mark->matricula_id, 'attendance.'.($status === 'ausente' ? 'absent' : 'late'), 'mark:'.$version);
    }

    public function requestChanged(AsistenciaSolicitud $request): void
    {
        $event = match ($request->estado) {
            'revision_docente' => 'requested', 'pendiente_aprobacion' => 'forwarded',
            'aprobada' => 'approved', 'rechazada' => 'rejected', default => null,
        };
        $mark = DB::table('asistencia_solicitud_marcas')->where('solicitud_id', $request->id)->orderBy('asistencia_marca_id')->first();
        if ($event && $mark) {
            $this->deliver($mark->asistencia_marca_id, $request->matricula_id, 'attendance.justification.'.$event,
                'request:'.$request->id.':'.$request->estado, $request->id);
        }
    }

    private function deliver(int $mark, int $enrollmentId, string $event, string $key, ?int $request = null): void
    {
        $enrollment = Matricula::whereKey($enrollmentId)->where('estado', 'activa')->with('estudiante')->first();
        $student = $enrollment?->estudiante;
        if (! $student || $student->status !== User::STATUS_ACTIVE || ! $student->hasRole('estudiante')
            || ! ($student->can('asistencia.consultar_propia') || $student->can('asistencia.justificar_propia'))) {
            return;
        }
        $inserted = DB::table('user_notification_updates')->insertOrIgnore([
            'url_token' => Str::random(24), 'user_id' => $student->id, 'asistencia_marca_id' => $mark,
            'asistencia_solicitud_id' => $request, 'event' => $event, 'deduplication_key' => $key, 'created_at' => now('UTC'),
        ]);
        if ($inserted) {
            app(RealtimeChanges::class)->record((string) tenant()->getTenantKey(), 'attendance');
        }
    }

    /** Current ownership and permissions are checked again when reading the feed. */
    public function readable(User $user): Builder
    {
        return DB::table('asistencia_marcas as mark')
            ->join('matriculas as enrollment', 'enrollment.id', '=', 'mark.matricula_id')
            ->join('asistencia_clases as class', 'class.id', '=', 'mark.asistencia_clase_id')
            ->join('materias as subject', 'subject.id', '=', 'class.materia_id')
            ->join('grupos as student_group', 'student_group.id', '=', 'class.grupo_id')
            ->join('grados as grade', 'grade.id', '=', 'student_group.grado_id')
            ->join('anos_lectivos as year', 'year.id', '=', 'class.ano_lectivo_id')
            ->where('enrollment.estudiante_id', $user->id)->where('enrollment.estado', 'activa')
            ->whereColumn('enrollment.grupo_id', 'class.grupo_id')->whereColumn('enrollment.ano_lectivo_id', 'class.ano_lectivo_id');
    }
}
