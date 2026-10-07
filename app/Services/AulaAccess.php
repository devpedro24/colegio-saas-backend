<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Aula;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Matricula;
use App\Models\User;
use Illuminate\Support\Carbon;

final class AulaAccess
{
    public function __construct(private readonly AcademicPlanAccess $plan) {}

    public function view(User $user, Aula $aula): void
    {
        $this->plan->requireAula();
        abort_unless($this->canView($user, $aula), 403, 'No tienes acceso a esta aula.');
    }

    public function canView(User $user, Aula $aula): bool
    {
        if ($user->can('aula.ver_todas')) return true;
        if ($user->can('aula.ver_asignadas') && $this->assignment($aula)?->docente_id === $user->id) return true;

        return $user->can('aula.ver_propias') && $this->enrollment($user, $aula) !== null;
    }

    public function manages(User $user, Aula $aula, string $permission = 'aula.recursos.gestionar'): void
    {
        $this->view($user, $aula);
        abort_unless($this->canManage($user, $aula, $permission), 403);
    }

    public function canManage(User $user, Aula $aula, string $permission = 'aula.recursos.gestionar'): bool
    {
        return $user->can($permission) && ($user->can('aula.ver_todas')
            || $this->assignment($aula)?->docente_id === $user->id);
    }

    public function content(User $user, Aula $aula, string $action, bool $quiz = false): void
    {
        $this->view($user, $aula);
        abort_unless($this->canContent($user, $aula, $action, $quiz), 403);
    }

    public function canContent(User $user, Aula $aula, string $action, bool $quiz = false): bool
    {
        return $this->canManage($user, $aula)
            && $this->canManage($user, $aula, 'aula.contenido.'.$action)
            && (! $quiz || $this->canManage($user, $aula, 'aula.evaluaciones.gestionar'));
    }

    public function isStudent(User $user, Aula $aula): bool
    {
        return ! $user->can('aula.ver_todas') && $this->assignment($aula)?->docente_id !== $user->id
            && $this->enrollment($user, $aula) !== null;
    }

    public function enrollment(User $user, Aula $aula): ?Matricula
    {
        return Matricula::where('estudiante_id', $user->id)->where('ano_lectivo_id', $aula->ano_lectivo_id)
            ->where('grupo_id', $aula->grupo_id)->where('estado', 'activa')->first();
    }

    public function assignment(Aula $aula): ?AsignacionDocente
    {
        return AsignacionDocente::where('ano_lectivo_id', $aula->ano_lectivo_id)->where('grupo_id', $aula->grupo_id)
            ->where('materia_id', $aula->materia_id)->first();
    }

    public function visible(AulaRecurso $resource): bool
    {
        if (! $this->listed($resource)) return false;
        $now = Carbon::now('UTC');

        // El vencimiento cierra interacciones, no el acceso a evidencias y retroalimentación.
        return ! $resource->disponible_desde || ! $now->lt($resource->disponible_desde);
    }

    public function listed(AulaRecurso $resource): bool
    {
        $section = $resource->seccion;
        if ($resource->trashed() || ! $section || $section->trashed()) return false;
        if (! $section->visible_estudiantes || ! $resource->visible_estudiantes
            || ! in_array($resource->estado, ['publicado', 'programado', 'cerrado'], true)) return false;
        if ($resource->estado === 'programado' && ! $resource->disponible_desde) return false;

        return true;
    }

    public function readResource(User $user, AulaRecurso $resource): void
    {
        abort_if($resource->trashed() || ! $resource->seccion || $resource->seccion->trashed(), 404);
        $this->view($user, $resource->seccion->aula);
        abort_if($this->isStudent($user, $resource->seccion->aula) && ! $this->visible($resource), 404);
    }
}
