<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Evento;
use App\Models\Academico\Grupo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EventAccess
{
    public function rector(User $user): bool
    {
        return $user->can('eventos.gestionar');
    }

    public function teacher(User $user): bool
    {
        return $user->can('eventos.publicar_asignados');
    }

    public function unrestricted(): bool
    {
        return (bool) DB::table('configuracion_eventos')->where('id', 1)->value('docentes_cualquier_grupo');
    }

    public function groupIds(User $user): array
    {
        if ($this->teacher($user)) {
            return AsignacionDocente::where('docente_id', $user->id)->pluck('grupo_id')->unique()->all();
        }
        if (Schema::hasTable('matriculas')) {
            return DB::table('matriculas')->where('estudiante_id', $user->id)->where('estado', 'activa')->pluck('grupo_id')->all();
        }

        return [];
    }

    public function visible(User $user): Builder
    {
        $query = Evento::query();
        if ($this->rector($user) || ($this->teacher($user) && $this->unrestricted())) {
            return $query;
        }
        $groups = $this->groupIds($user);

        return $query->where(function ($query) use ($groups, $user) {
            $query->where('institucional', true)->orWhere('created_by', $user->id)
                ->orWhereHas('grupos', fn ($q) => $q->whereIn('grupos.id', $groups));
        });
    }

    public function authorizeWrite(User $user, array $data, ?Evento $event = null): void
    {
        $manager = $this->rector($user);
        abort_if($event && ! $manager && $event->created_by != $user->id, 403, 'Solo puedes editar tus propios eventos.');
        abort_if($event?->institucional && ! $user->can('eventos.publicar_institucional'), 403, 'No tienes permiso para modificar eventos institucionales.');
        if ($data['institucional']) {
            abort_unless($user->can('eventos.publicar_institucional'), 403, 'No tienes permiso para publicar para todo el colegio.');
            abort_if(! empty($data['grupo_ids']) || ! empty($data['materia_id']), 422, 'Un evento institucional no se restringe a grupos o asignaturas.');

            return;
        }
        abort_unless($manager || $this->teacher($user), 403, 'No tienes permiso para publicar eventos.');
        $groups = array_unique($data['grupo_ids'] ?? []);
        abort_if($groups === [], 422, 'Selecciona al menos un grupo.');
        abort_unless(Grupo::where('estado', 'activo')->whereIn('id', $groups)->count() === count($groups), 422, 'Selecciona grupos activos de este colegio.');
        if (! $manager && ! $this->unrestricted()) {
            $authorized = AsignacionDocente::where('docente_id', $user->id)
                ->when(! empty($data['materia_id']), fn ($q) => $q->where('materia_id', $data['materia_id']))
                ->whereHas('anoLectivo', fn ($q) => $q->whereNotIn('estado', ['cerrado', 'archivado']))
                ->whereIn('grupo_id', $groups)->distinct()->pluck('grupo_id')->all();
            abort_if(count(array_diff($groups, $authorized)) > 0, 403, 'Solo puedes publicar en los grupos y asignaturas que tienes asignados.');
        }
    }
}
