<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Evento;
use App\Models\User;
use App\Support\Realtime\RealtimeChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** In-app news is independent from the optional event email outbox. */
class EventNotifications
{
    public function changed(Evento $event, string $action, ?string $revision = null): void
    {
        if ($event->estado !== 'publicado' || ! app(TenantPlanAccess::class)->has('comunicacion_basica')) {
            return;
        }
        $event->loadMissing('grupos');
        $groups = $event->grupos->pluck('id')->all();
        $students = DB::table('matriculas')->join('grupos', 'grupos.id', '=', 'matriculas.grupo_id')
            ->join('anos_lectivos', 'anos_lectivos.id', '=', 'matriculas.ano_lectivo_id')
            ->where('matriculas.estado', 'activa')->where('grupos.estado', 'activo')
            ->whereNull('grupos.deleted_at')->whereNull('anos_lectivos.deleted_at')
            ->whereNotIn('anos_lectivos.estado', ['cerrado', 'archivado'])
            ->when(! $event->institucional, fn ($q) => $q->whereIn('matriculas.grupo_id', $groups))
            ->pluck('matriculas.estudiante_id')->all();
        $teachers = AsignacionDocente::whereIn('grupo_id', $groups)
            ->whereHas('anoLectivo', fn ($q) => $q->whereNotIn('estado', ['cerrado', 'archivado']))->pluck('docente_id')->all();
        $query = User::where('status', User::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereHas('roles')->orWhereHas('permissions'))->with('roles.permissions', 'permissions');
        if (! $event->institucional) {
            $query->where(fn ($q) => $q->whereIn('id', array_unique([...$students, ...$teachers, $event->created_by]))
                ->orWhereHas('permissions', fn ($q) => $q->where('name', 'eventos.gestionar'))
                ->orWhereHas('roles.permissions', fn ($q) => $q->where('name', 'eventos.gestionar')));
        }
        $inserted = false;
        foreach ($query->get() as $user) {
            $eligible = $event->institucional || $user->id === $event->created_by || $user->can('eventos.gestionar')
                || ($user->can('eventos.publicar_asignados') ? in_array($user->id, $teachers, true) : in_array($user->id, $students, true));
            if (! $eligible) {
                continue;
            }
            $inserted = DB::table('user_notification_updates')->insertOrIgnore([
                'url_token' => Str::random(24), 'user_id' => $user->id, 'evento_id' => $event->id,
                'event' => 'calendar.event.'.$action, 'deduplication_key' => $action.':'.($revision ?? $event->version),
                'created_at' => now('UTC'),
            ]) > 0 || $inserted;
        }
        if ($inserted) {
            app(RealtimeChanges::class)->record((string) tenant()->getTenantKey(), 'events');
        }
    }
}
