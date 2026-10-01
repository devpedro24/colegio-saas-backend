<?php

declare(strict_types=1);

namespace App\Support;

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

/** Explicit public evaluation DTOs; internal database keys stay on the server. */
final class EvaluationOpaquePresenter
{
    public static function token(string $resource, int|string|null $id): ?string
    {
        return $id === null ? null : OpaqueUrlToken::for($resource, $id);
    }

    public static function year(AnoLectivo $year): array
    {
        return ['url_token' => self::token('ano-lectivo', $year->id),
            'nombre' => $year->nombre, 'estado' => $year->estado];
    }

    public static function period(Periodo $period): array
    {
        return ['url_token' => self::token('periodo', $period->id),
            'ano_lectivo_token' => self::token('ano-lectivo', $period->ano_lectivo_id),
            'nombre' => $period->nombre, 'orden' => $period->orden,
            'fecha_inicio' => $period->fecha_inicio, 'fecha_fin' => $period->fecha_fin,
            'peso' => $period->peso, 'estado' => $period->estado];
    }

    public static function user(User $user): array
    {
        return ['url_token' => self::token('usuario', $user->id), 'name' => $user->name];
    }

    public static function group(Grupo $group): array
    {
        return AcademicOpaqueRecord::present($group, 'grupo');
    }

    public static function subject(Materia $subject): array
    {
        return AcademicOpaqueRecord::present($subject, 'materia');
    }

    public static function assignment(AsignacionDocente $assignment): array
    {
        return ['url_token' => self::token('asignacion-docente', $assignment->id),
            'ano_lectivo_token' => self::token('ano-lectivo', $assignment->ano_lectivo_id),
            'grupo_token' => self::token('grupo', $assignment->grupo_id),
            'materia_token' => self::token('materia', $assignment->materia_id),
            'docente_token' => self::token('usuario', $assignment->docente_id),
            'materia' => $assignment->relationLoaded('materia') && $assignment->materia ? self::subject($assignment->materia) : null,
            'grupo' => $assignment->relationLoaded('grupo') && $assignment->grupo ? self::group($assignment->grupo) : null,
            'docente' => $assignment->relationLoaded('docente') && $assignment->docente ? self::user($assignment->docente) : null];
    }

    public static function enrollment(Matricula $enrollment): array
    {
        return ['url_token' => self::token('matricula', $enrollment->id),
            'ano_lectivo_token' => self::token('ano-lectivo', $enrollment->ano_lectivo_id),
            'grupo_token' => self::token('grupo', $enrollment->grupo_id),
            'estudiante_token' => self::token('usuario', $enrollment->estudiante_id),
            'estado' => $enrollment->estado,
            'grupo' => $enrollment->relationLoaded('grupo') && $enrollment->grupo ? self::group($enrollment->grupo) : null,
            'estudiante' => $enrollment->relationLoaded('estudiante') && $enrollment->estudiante ? self::user($enrollment->estudiante) : null];
    }

    public static function component(ComponenteEvaluacion $component): array
    {
        $public = ['url_token' => self::token('componente-evaluacion', $component->id),
            'asignacion_token' => self::token('asignacion-docente', $component->asignacion_id),
            'periodo_token' => self::token('periodo', $component->periodo_id),
            'nombre' => $component->nombre, 'peso' => $component->peso, 'modo' => $component->modo];
        if ($component->relationLoaded('actividades')) {
            $public['actividades'] = $component->actividades->map(self::activity(...))->all();
        }

        return $public;
    }

    public static function activity(ActividadEvaluacion $activity): array
    {
        return ['url_token' => self::token('actividad-evaluacion', $activity->id),
            'componente_token' => self::token('componente-evaluacion', $activity->componente_id),
            'nombre' => $activity->nombre, 'fecha' => $activity->fecha, 'peso' => $activity->peso, 'version' => $activity->version];
    }

    public static function grade(Calificacion $grade): array
    {
        return ['matricula_token' => self::token('matricula', $grade->matricula_id),
            'actividad_token' => self::token('actividad-evaluacion', $grade->actividad_id),
            'valor' => $grade->valor, 'observacion' => $grade->observacion,
            'version' => $grade->version];
    }

    public static function config(array $config): array
    {
        $scaleId = $config['escala_id'] ?? null;
        $methodId = $config['metodo_id'] ?? null;
        unset($config['escala_id'], $config['metodo_id']);

        return [...$config,
            'escala_token' => self::token('escala-valorativa', $scaleId),
            'metodo_token' => self::token('metodo-aprobacion', $methodId)];
    }

    public static function result(array $result): array
    {
        // Preserve the engine's rounding and exact arithmetic; only remove
        // presentation padding at the public decimal boundary.
        foreach (['raw_value', 'display_value'] as $field) {
            if (isset($result[$field])) {
                $result[$field] = AcademicDecimal::normalize($result[$field]);
            }
        }
        if (isset($result['trace'])) {
            $result['trace'] = self::trace($result['trace']);
        }
        if (isset($result['resultado_original']) && is_array($result['resultado_original'])) {
            $result['resultado_original'] = self::result($result['resultado_original']);
        }

        return $result;
    }

    public static function report(array $report): array
    {
        $student = $report['estudiante'];
        $public = [
            'tipo' => $report['tipo'], 'generado_en' => $report['generado_en'],
            'institucion' => $report['institucion'],
            'estudiante' => ['url_token' => self::token('usuario', $student['id']), 'name' => $student['name']],
            'grupo' => $report['grupo'], 'grado' => $report['grado'], 'ano' => $report['ano'],
            'configuracion' => self::config($report['configuracion']),
            'periodos' => collect($report['periodos'])->map(fn ($period) => [
                'url_token' => self::token('periodo', $period['id']),
                'nombre' => $period['nombre'], 'peso' => $period['peso'], 'estado' => $period['estado'],
            ])->all(),
            'periodo_sumatorio' => $report['periodo_sumatorio'],
            'asignaturas' => collect($report['asignaturas'])->map(fn ($subject) => [
                'materia_token' => self::token('materia', $subject['materia_id']),
                'nombre' => $subject['nombre'], 'area_token' => self::token('area', $subject['area_id']),
                'peso_area' => $subject['peso_area'],
                'periodos' => array_map(self::resultWithPeriod(...), $subject['periodos']),
                'anual' => self::result($subject['anual']),
            ])->all(),
            'areas' => collect($report['areas'])->map(fn ($area) => [
                'area_token' => self::token('area', $area['area_id']), 'nombre' => $area['nombre'],
                'periodos' => array_map(self::resultWithPeriod(...), $area['periodos']),
                'anual' => self::result($area['anual']),
            ])->all(),
            'advertencias' => $report['advertencias'],
        ];

        return $public;
    }

    private static function resultWithPeriod(array $result): array
    {
        $periodId = $result['periodo_id'];
        unset($result['periodo_id']);

        return ['periodo_token' => self::token('periodo', $periodId), ...self::result($result)];
    }

    private static function trace(array $trace): array
    {
        $public = [];
        foreach ($trace as $key => $value) {
            if ($key === 'reference' && is_string($value) && preg_match('/\A(componente|actividad|materia|periodo|preinforme):([0-9]+)\z/', $value, $match)) {
                $resource = match ($match[1]) {
                    'componente' => 'componente-evaluacion', 'actividad' => 'actividad-evaluacion',
                    default => $match[1],
                };
                $public[$key] = $match[1].':'.self::token($resource, $match[2]);
            } elseif (is_array($value)) {
                $public[$key] = self::trace($value);
            } else {
                $public[$key] = $value;
            }
        }

        return $public;
    }
}
