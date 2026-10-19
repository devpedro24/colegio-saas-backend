<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Matricula;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Learning objectives are descriptive; SIEE results and observations evaluate students. */
final class IndicatorBulletinService
{
    public function forEnrollment(Matricula $enrollment, Collection $periods, Collection $subjects): array
    {
        if ($periods->isEmpty() || $subjects->isEmpty()) {
            return [];
        }
        $group = $enrollment->grupo;
        $bindings = DB::table('indicadores_vinculos as link')
            ->join('indicadores_base as base', 'base.id', '=', 'link.indicador_id')
            ->join('indicadores_revisiones as revision', 'revision.id', '=', 'link.revision_id')
            ->where('link.ano_lectivo_id', $enrollment->ano_lectivo_id)
            ->where('link.grado_id', $group->grado_id)
            ->whereIn('link.materia_id', $subjects->pluck('materia_id'))
            ->where('link.estado', 'activo')
            ->orderBy('base.orden')->orderBy('link.id')
            ->get(['link.id', 'link.materia_id', 'link.periodos_orden', 'revision.texto as texto_base']);
        if ($bindings->isEmpty()) {
            return [];
        }
        $variants = DB::table('indicadores_variantes')->whereIn('vinculo_id', $bindings->pluck('id'))
            ->where('grupo_id', $group->id)->get()->groupBy('vinculo_id');
        $subjectNames = $subjects->keyBy('materia_id');
        $rows = [];
        foreach ($periods as $period) {
            foreach ($bindings as $binding) {
                $orders = $binding->periodos_orden === null ? null : json_decode($binding->periodos_orden, true);
                if ($orders !== null && ! in_array($period->orden, $orders, true)) {
                    continue;
                }
                $variant = $variants->get($binding->id)?->firstWhere('periodo_orden', $period->orden)
                    ?? $variants->get($binding->id)?->firstWhere('periodo_orden', 0);
                $subject = $subjectNames->get($binding->materia_id);
                $rows[] = [
                    'periodo_id' => $period->id, 'materia_id' => $binding->materia_id,
                    'periodo' => $period->nombre,
                    'materia' => $subject['nombre'], 'area_id' => $subject['area_id'],
                    'texto' => $variant?->texto ?? $binding->texto_base,
                ];
            }
        }

        return $rows;
    }
}
