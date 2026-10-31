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
        $scope = IndicatorPolicy::forYear($enrollment->ano_lectivo_id)['alcance'];
        $html = app(IndicatorHtml::class);
        $bindings = DB::table('indicadores_vinculos as link')
            ->join('indicadores_base as base', 'base.id', '=', 'link.indicador_id')
            ->join('indicadores_revisiones as revision', 'revision.id', '=', 'link.revision_id')
            ->where('link.ano_lectivo_id', $enrollment->ano_lectivo_id)
            ->where('link.grado_id', $group->grado_id)
            ->when($scope === 'grado', fn ($query) => $query->whereNull('link.grupo_id'))
            ->when($scope === 'grupo', fn ($query) => $query->where('link.grupo_id', $group->id))
            ->when($scope === 'mixto', fn ($query) => $query->where(fn ($inner) => $inner->whereNull('link.grupo_id')->orWhere('link.grupo_id', $group->id)))
            ->whereIn('link.materia_id', $subjects->pluck('materia_id'))
            ->where('link.estado', 'activo')
            ->orderBy('base.orden')->orderBy('link.id')
            ->get(['link.id', 'link.materia_id', 'link.periodos_orden', 'revision.texto as texto_base']);
        $variants = DB::table('indicadores_variantes')->whereIn('vinculo_id', $bindings->pluck('id'))
            ->where('grupo_id', $group->id)->get()->groupBy('vinculo_id');
        $subjectNames = $subjects->keyBy('materia_id');
        $rowsByCell = [];
        foreach ($periods as $period) {
            foreach ($bindings as $binding) {
                $orders = $binding->periodos_orden === null ? null : json_decode($binding->periodos_orden, true);
                if ($orders !== null && ! in_array($period->orden, $orders, true)) {
                    continue;
                }
                $variant = $variants->get($binding->id)?->firstWhere('periodo_orden', $period->orden)
                    ?? $variants->get($binding->id)?->firstWhere('periodo_orden', 0);
                $subject = $subjectNames->get($binding->materia_id);
                $text = $variant?->texto ?? $binding->texto_base;
                $rowsByCell[$period->id.':'.$binding->materia_id][] = [
                    'periodo_id' => $period->id, 'materia_id' => $binding->materia_id,
                    'periodo' => $period->nombre,
                    'materia' => $subject['nombre'], 'area_id' => $subject['area_id'],
                    'texto' => $text,
                    'texto_html' => $html->sanitize(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                ];
            }
        }

        if ($scope !== 'grado') {
            $contents = DB::table('indicadores_contenidos_grupo')->where('grupo_id', $group->id)
                ->whereIn('periodo_id', $periods->pluck('id'))
                ->whereIn('materia_id', $subjects->pluck('materia_id'))->get();
            $periodNames = $periods->keyBy('id');
            foreach ($contents as $content) {
                $safe = $html->sanitize($content->texto_html);
                $cell = $content->periodo_id.':'.$content->materia_id;
                if ($html->plain($safe) === '') {
                    $rowsByCell[$cell] = [];

                    continue;
                }
                $subject = $subjectNames->get($content->materia_id);
                $rowsByCell[$cell] = [[
                    'periodo_id' => $content->periodo_id, 'materia_id' => $content->materia_id,
                    'periodo' => $periodNames->get($content->periodo_id)->nombre,
                    'materia' => $subject['nombre'], 'area_id' => $subject['area_id'],
                    'texto' => $html->plain($safe), 'texto_html' => $safe,
                ]];
            }
        }

        $rows = [];
        foreach ($periods as $period) {
            foreach ($subjects as $subject) {
                array_push($rows, ...($rowsByCell[$period->id.':'.$subject['materia_id']] ?? []));
            }
        }

        return $rows;
    }
}
