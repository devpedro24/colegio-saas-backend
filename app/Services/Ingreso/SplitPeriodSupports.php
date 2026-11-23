<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Support\OpaqueUrlToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SplitPeriodSupports
{
    public const TABLE = 'matricula_actividades_apoyo';

    /** Include earlier revisions so a later transfer preserves completed segments. */
    public static function load(Collection $cases): Collection
    {
        if ($cases->isEmpty()) {
            return collect();
        }
        return DB::table(self::TABLE.' as a')
            ->join('actividades_evaluacion as act', 'act.id', '=', 'a.actividad_id')
            ->join('componentes_evaluacion as c', 'c.id', '=', 'act.componente_id')
            ->join('asignaciones_docentes as g', 'g.id', '=', 'c.asignacion_id')
            ->whereIn('a.matricula_id', $cases->pluck('matricula_id')->unique())
            ->whereIn('c.periodo_id', $cases->pluck('periodo_id')->unique())
            ->whereIn('g.materia_id', $cases->pluck('materia_id')->unique())
            ->get(['a.*','c.periodo_id','g.materia_id','g.grupo_id','act.fecha'])
            ->groupBy('matricula_id');
    }

    public static function forGroup(Collection $loaded, object $case, int $group): Collection
    {
        $spans = collect(SplitPeriodGrades::datesFor($case, $group));
        return $loaded->get($case->matricula_id, collect())->filter(fn ($a) =>
            $a->materia_id === $case->materia_id && $a->periodo_id === $case->periodo_id && $a->grupo_id === $group
            && $spans->contains('desde', $a->tramo_desde)
            && ! $spans->contains(fn ($s) => $a->fecha >= $s['desde'] && $a->fecha <= $s['hasta']))
            ->keyBy('actividad_id');
    }

    public static function sheet(object $support): array
    {
        return ['actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $support->actividad_id),
            'fecha_entrega' => $support->fecha_entrega];
    }
}
