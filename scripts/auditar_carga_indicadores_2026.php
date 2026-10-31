<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\IndicatorHtml;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (Tenant::query()->orderBy('slug')->get() as $tenant) {
    $result = $tenant->run(function (): array {
        $years = DB::table('anos_lectivos')->whereNull('deleted_at')->orderByDesc('fecha_inicio')
            ->get(['id', 'nombre', 'estado']);

        return $years->map(function ($year): array {
            $periods = DB::table('periodos')->where('ano_lectivo_id', $year->id)
                ->orderBy('orden')->get(['id', 'nombre', 'orden', 'estado']);
            $groups = DB::table('grupos as g')->join('grados as d', 'd.id', '=', 'g.grado_id')
                ->where('g.ano_lectivo_id', $year->id)->whereNull('g.deleted_at')
                ->whereNull('d.deleted_at')->orderBy('d.id')->orderBy('g.id')
                ->get(['g.id', 'g.grado_id', 'g.nombre as grupo', 'd.nombre as grado']);
            $grades = $groups->groupBy('grado_id')->map(function ($gradeGroups) use ($year): array {
                $grade = $gradeGroups->first();

                return [
                    'grado' => $grade->grado,
                    'grupos' => $gradeGroups->pluck('grupo')->all(),
                    'materias' => DB::table('materias_curriculares as mc')
                        ->join('materias as m', 'm.id', '=', 'mc.materia_id')
                        ->where('mc.ano_lectivo_id', $year->id)
                        ->where('mc.grado_id', $grade->grado_id)
                        ->where('m.estado', 'activo')->orderBy('m.nombre')->pluck('m.nombre')->all(),
                ];
            })->values();
            $content = DB::table('indicadores_contenidos_grupo')
                ->where('ano_lectivo_id', $year->id)->get();
            $html = app(IndicatorHtml::class);
            $gradeDifferences = DB::table('indicadores_contenidos_grupo as i')
                ->join('grupos as g', 'g.id', '=', 'i.grupo_id')
                ->where('i.ano_lectivo_id', $year->id)
                ->select('g.grado_id', 'i.materia_id', 'i.periodo_id')
                ->groupBy('g.grado_id', 'i.materia_id', 'i.periodo_id')
                ->havingRaw('COUNT(DISTINCT i.texto_html) > 1')->get()->count();

            return [
                'ano' => $year->nombre,
                'estado' => $year->estado,
                'periodos' => $periods->map(fn ($period) => [
                    'nombre' => $period->nombre, 'orden' => $period->orden, 'estado' => $period->estado,
                    'indicadores' => $content->where('periodo_id', $period->id)->count(),
                ])->all(),
                'grados' => $grades->all(),
                'contenidos' => $content->count(),
                'vacios' => $content->filter(fn ($row) => $html->plain($row->texto_html) === '')->count(),
                'con_lista' => $content->filter(fn ($row) => str_contains($row->texto_html, '<ul>'))->count(),
                'materia_periodo_con_textos_distintos_entre_grupos_del_mismo_grado' => $gradeDifferences,
                'boletines_emitidos_tenant' => DB::table('boletin_emisiones')->count(),
            ];
        })->all();
    });
    echo json_encode(['tenant' => $tenant->slug, 'anos' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
}
