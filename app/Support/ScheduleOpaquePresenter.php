<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\SesionHorario;
use App\Models\User;

/** Whitelisted schedule DTOs for public APIs. */
final class ScheduleOpaquePresenter
{
    public static function assignment(AsignacionDocente $assignment): array
    {
        return EvaluationOpaquePresenter::assignment($assignment);
    }

    public static function user(User $user): array
    {
        return EvaluationOpaquePresenter::user($user);
    }

    public static function session(SesionHorario $session): array
    {
        $public = [
            'url_token' => OpaqueUrlToken::for('sesion-horario', $session->id),
            'ano_lectivo_token' => EvaluationOpaquePresenter::token('ano-lectivo', $session->ano_lectivo_id),
            'asignacion_token' => EvaluationOpaquePresenter::token('asignacion-docente', $session->asignacion_id),
            'grupo_token' => EvaluationOpaquePresenter::token('grupo', $session->grupo_id),
            'materia_token' => EvaluationOpaquePresenter::token('materia', $session->materia_id),
            'docente_token' => EvaluationOpaquePresenter::token('usuario', $session->docente_id),
            'dia' => $session->dia,
            'bloque_horario_token' => EvaluationOpaquePresenter::token('bloque-horario', $session->bloque_horario_id),
            'hora_inicio' => $session->hora_inicio,
            'hora_fin' => $session->hora_fin,
            'espacio_fisico_token' => EvaluationOpaquePresenter::token('espacio-fisico', $session->espacio_fisico_id),
        ];
        foreach (['grupo' => 'grupo', 'materia' => 'materia', 'bloque' => 'bloque-horario', 'espacio' => 'espacio-fisico'] as $relation => $resource) {
            if ($session->relationLoaded($relation)) {
                $public[$relation] = $session->{$relation} ? AcademicOpaqueRecord::present($session->{$relation}, $resource) : null;
            }
        }
        if ($session->relationLoaded('docente')) {
            $public['docente'] = $session->docente ? self::user($session->docente) : null;
        }

        return $public;
    }
}
