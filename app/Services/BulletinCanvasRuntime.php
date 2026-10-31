<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Grado;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\PromocionAcademica;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\StoredFilePublicToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Frozen, server-owned bindings. No client academic values enter this snapshot. */
final class BulletinCanvasRuntime
{
    public function build(Matricula $enrollment, ?Periodo $period, array $report, array $snapshot, ?string $logo): array
    {
        $config = $report['configuracion'];
        $scale = EscalaValorativa::find($config['escala_id']);
        $visual = app(EscalaVisualService::class)->forGroup($enrollment->grupo);
        $ranges = $scale?->desempenos ?? [];
        $canvas = $snapshot['plantilla']['configuracion']['canvas'];
        $blocks = collect($canvas['pages'])->reject(fn ($page) => $page['hidden'] ?? false)
            ->flatMap(fn ($page) => $page['blocks'])->reject(fn ($node) => $node['hidden'] ?? false);
        $needsPerformance = $blocks->contains(fn ($node) => collect($node['table']['columns'] ?? [])->contains('key', 'performance'));
        abort_if(! $visual && $needsPerformance && $ranges === [], 422,
            __('Configura los rangos de desempeño de la escala en el SIEE antes de emitir con esta columna.'));
        abort_if(! $config['usar_areas'] && $blocks->contains(fn ($node) => in_array($node['table']['rowMode'] ?? '', ['areas', 'areas-subjects'], true)),
            422, __('Esta plantilla usa resultados por área. Activa su cálculo en el SIEE o cambia la tabla a asignaturas.'));
        $subjects = collect($report['asignaturas']);
        $hours = Materia::whereIn('id', $subjects->pluck('materia_id'))->pluck('intensidad_horaria', 'id');
        $areas = collect($report['areas']);
        $areaNames = DB::table('areas')->whereIn('id', $subjects->pluck('area_id')->filter())->pluck('nombre', 'id');
        $choose = static fn (array $row) => $period
            ? collect($row['periodos'])->firstWhere('periodo_id', $period->id) : $row['anual'];
        $icons = [];
        $value = function (array $result) use ($ranges, &$icons): array {
            $choice = $result['valoracion'] ?? null;
            $token = $choice['url_token'] ?? null;
            if ($token && ! array_key_exists($token, $icons)) {
                $option = OpaqueUrlToken::find('escala-opcion', $token, EscalaOpcion::query());
                $icons[$token] = ($option?->imagen_path ? $this->image('tenant', $option->imagen_path) : null)
                    ?? $this->emojiImage($choice['emoji'] ?? '');
            }

            return ['display' => $choice['nombre'] ?? $result['display_value'] ?? '',
                'performance' => $choice['nombre'] ?? BulletinPerformance::label($result['display_value'] ?? null, $ranges),
                'iconKey' => $token, 'emoji' => $choice['emoji'] ?? '',
                'approved' => (bool) ($result['aprobado'] ?? false)];
        };
        $rows = $subjects->sortBy('nombre')->values()->map(function (array $row, int $i) use ($snapshot, $hours, $areaNames, $choose, $value): array {
            return ['key' => 'subject-'.($i + 1), 'subject' => $row['nombre'], 'area' => $areaNames->get($row['area_id'], ''),
                'hours' => $hours->get($row['materia_id']) ?? '', ...$value($choose($row)),
                'periodDisplay' => collect($row['periodos'])->map(fn ($result) => $value($result)['display'])->all(),
                'indicators' => collect($snapshot['indicadores'])->where('materia_id', $row['materia_id'])->values()
                    ->map(fn ($indicator, $j) => ['key' => 'indicator-'.$i.'-'.$j, 'text' => $indicator['texto'],
                        'html' => app(IndicatorHtml::class)->sanitize($indicator['texto_html'] ?? htmlspecialchars($indicator['texto'], ENT_QUOTES, 'UTF-8'))])->all()];
        })->all();
        $areaRows = $areas->sortBy('nombre')->values()->map(function (array $row, int $i) use ($choose, $value, $rows): array {
            $children = array_values(array_filter($rows, fn ($subject) => $subject['area'] === $row['nombre']));

            return ['key' => 'area-'.($i + 1), 'subject' => $row['nombre'], 'area' => $row['nombre'], 'isArea' => true,
                'hours' => array_sum(array_column($children, 'hours')), ...$value($choose($row)), 'children' => $children,
                'periodDisplay' => collect($row['periodos'])->map(fn ($result) => $value($result)['display'])->all(),
                'indicators' => array_merge([], ...array_column($children, 'indicators'))];
        })->all();
        $averageSource = $config['usar_areas'] ? $areas : $subjects;
        $average = app(GradeCalculationService::class)->result(['mode' => 'SIMPLE_AVERAGE',
            'inputs' => $averageSource->map(fn ($row) => ['value' => $choose($row)['exact_value']])->values()->all()], $config);
        $enrollment->loadMissing('grupo.grado.nivel');
        $director = DB::table('directores_grupo')->where('grupo_id', $enrollment->grupo_id)->value('docente_id');
        $decision = $period ? null : PromocionAcademica::where('matricula_id', $enrollment->id)->first();
        $nextGrade = $decision?->grado_destino_id ? Grado::find($decision->grado_destino_id)?->nombre : '';
        $institution = [
            'name' => $snapshot['colegio']['nombre'], 'nit' => $snapshot['colegio']['nit'] ?? '',
            'resolution' => $snapshot['colegio']['resolucion'] ?? '', 'address' => $snapshot['colegio']['direccion'] ?? '',
            'phone' => $snapshot['colegio']['telefono'] ?? '', 'email' => $snapshot['colegio']['correo'] ?? '', 'dane' => '',
            'principal' => User::whereHas('roles', fn ($query) => $query->where('name', 'rector'))->where('status', 'active')->orderBy('id')->value('name') ?? '',
            'logo' => $logo ? 'data:'.(new \finfo(FILEINFO_MIME_TYPE))->buffer($logo).';base64,'.base64_encode($logo) : '',
        ];
        $legend = $visual ? $visual->opciones->map(fn ($option) => $option->nombre)->all()
            : BulletinPerformance::legend($ranges);
        $fields = ['student' => $snapshot['estudiante']['nombre'], 'grade' => $snapshot['estudiante']['grado'],
            'group' => $snapshot['estudiante']['grupo'], 'level' => $enrollment->grupo->grado->nivel->nombre,
            'year' => (string) $snapshot['ano'], 'period' => $period?->nombre ?? __('Final'), 'teacher' => $director ? User::find($director)?->name ?? '' : '',
            'school' => $institution['name'], ...array_intersect_key($institution, array_flip(['nit', 'dane', 'resolution', 'address', 'phone', 'email', 'principal'])),
            'nextGrade' => $nextGrade, 'promotionVerb' => $decision?->resultado === 'promovido' ? __('cursó y aprobó') : __('cursó'),
            'promotion' => $snapshot['promocion'] ? $snapshot['promocion']['resultado'].($nextGrade ? ': '.$nextGrade : '') : '',
            'absence' => (string) $snapshot['asistencia']['ausentes'], 'average' => $average['display_value'],
            'observation' => $snapshot['observacion'] ?? '', 'scaleLegend' => implode("\n", $legend)];
        $assets = [];
        foreach ($canvas['assets'] ?? [] as $key => $token) {
            $file = StoredFilePublicToken::findForTenant((string) tenant()->getKey(), $token);
            abort_unless($file && str_contains($file->path, '/bulletin-templates/assets/'), 422, __('Una imagen de la plantilla ya no está disponible.'));
            $assets[$key] = $this->image($file->disk, $file->path);
            abort_unless($assets[$key], 422, __('Una imagen de la plantilla ya no está disponible.'));
        }

        return ['actual' => true, 'fields' => $fields, 'rows' => $rows, 'areaRows' => $areaRows,
            'icons' => $icons, 'visual' => (bool) $visual, 'periodCount' => count($report['periodos']),
            'year' => $snapshot['ano'], 'isPending' => false, 'scaleRows' => $legend, 'institution' => $institution, 'assets' => $assets];
    }

    private function image(string $disk, string $path): ?string
    {
        if (! Storage::disk($disk)->exists($path) || Storage::disk($disk)->size($path) > 2_097_152) {
            return null;
        }
        $bytes = Storage::disk($disk)->get($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) ? 'data:'.$mime.';base64,'.base64_encode($bytes) : null;
    }

    /** Bundled glyphs keep configured emoji readable in PDF without remote fonts. */
    private function emojiImage(string $emoji): ?string
    {
        if ($emoji === '' || mb_strlen($emoji) > 16) {
            return null;
        }
        $characters = preg_split('//u', $emoji, -1, PREG_SPLIT_NO_EMPTY);
        $key = implode('-', array_map(fn ($character) => dechex(mb_ord($character, 'UTF-8')),
            array_values(array_filter($characters, fn ($character) => mb_ord($character, 'UTF-8') !== 0xFE0F))));
        $archive = new \ZipArchive;
        if ($archive->open(resource_path('bulletin-editor/fonts/emoji/glyphs.zip')) !== true) {
            return null;
        }
        $bytes = $archive->getFromName($key.'.png');
        $archive->close();

        return is_string($bytes) ? 'data:image/png;base64,'.base64_encode($bytes) : null;
    }
}
