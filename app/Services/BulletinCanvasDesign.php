<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Storage\StoredFilePublicToken;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A bounded presentation model. Academic values, runtime contexts and HTML are never accepted. */
final class BulletinCanvasDesign
{
    public const TYPES = ['header', 'title', 'student', 'grades', 'table', 'scale', 'pagebreak', 'indicators', 'observation', 'attendance', 'signature', 'promotion', 'text', 'field', 'rectangle', 'circle', 'line', 'image'];

    public const FIELDS = ['student', 'grade', 'group', 'level', 'year', 'period', 'teacher', 'school', 'nextGrade', 'promotionVerb', 'promotion', 'absence', 'average', 'observation', 'nit', 'dane', 'resolution', 'address', 'phone', 'email', 'principal', 'page', 'pages', 'scaleLegend'];

    public static function fonts(): array
    {
        static $fonts;
        if ($fonts !== null) {
            return $fonts;
        }
        $manifest = json_decode(file_get_contents(resource_path('bulletin-editor/fonts/catalog/manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        return $fonts = ['Inter', ...array_column($manifest, 'family'), 'Arial', 'Georgia'];
    }

    private static function fail(): never
    {
        throw ValidationException::withMessages(['configuracion.canvas' => __('El diseño contiene un elemento o valor no admitido.')]);
    }

    private static function pick(mixed $value, array $choices, mixed $default): mixed
    {
        if ($value === null) {
            return $default;
        }
        if (! in_array($value, $choices, true)) {
            self::fail();
        }

        return $value;
    }

    private static function number(mixed $value, float $default, float $min, float $max): float
    {
        if ($value === null) {
            return $default;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < $min || (float) $value > $max) {
            self::fail();
        }

        return (float) $value;
    }

    private static function string(mixed $value, string $default = '', int $max = 2500): string
    {
        if ($value === null) {
            return $default;
        }
        if (! is_string($value) || mb_strlen($value) > $max || str_contains($value, "\0")) {
            self::fail();
        }
        preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches);
        if (array_diff($matches[1], self::FIELDS)) {
            self::fail();
        }

        return $value;
    }

    private static function color(mixed $value, string $default = '#13264D', bool $transparent = false): string
    {
        if ($transparent && $value === 'transparent') {
            return 'transparent';
        }
        if ($value === null) {
            return $default;
        }
        if (! is_string($value) || ! preg_match('/\A#[0-9a-fA-F]{6}\z/D', $value)) {
            self::fail();
        }

        return $value;
    }

    private static function rule(array $raw): array
    {
        return ['enabled' => (bool) ($raw['enabled'] ?? true), 'color' => self::color($raw['color'] ?? null, '#6A23FF'),
            'width' => self::number($raw['width'] ?? null, 1, 0, 12),
            'style' => self::pick($raw['style'] ?? null, ['solid', 'dashed', 'dotted', 'double'], 'solid')];
    }

    private static function typography(array $raw): array
    {
        return ['font' => self::pick($raw['font'] ?? null, self::fonts(), 'Inter'),
            'fontSize' => self::number($raw['fontSize'] ?? null, 12, 8, 90),
            'color' => self::color($raw['color'] ?? null), 'bold' => (bool) ($raw['bold'] ?? false),
            'italic' => (bool) ($raw['italic'] ?? false), 'underline' => (bool) ($raw['underline'] ?? false),
            'align' => self::pick($raw['align'] ?? null, ['left', 'center', 'right'], 'left')];
    }

    private static function table(array $raw): array
    {
        $columns = $raw['columns'] ?? [['key' => 'subject', 'label' => 'Asignatura', 'width' => 60], ['key' => 'grade', 'label' => 'Valoración', 'width' => 40]];
        if (! is_array($columns) || count($columns) < 1 || count($columns) > 8) {
            self::fail();
        }
        $out = ['columns' => [], 'binding' => 'student-academic-results', 'filterSubjects' => false, 'subjectKeys' => []];
        foreach ($columns as $column) {
            $key = self::pick($column['key'] ?? null, ['subject', 'area', 'indicators', 'hours', 'grade', 'performance', 'periods'], 'subject');
            if (in_array($key, array_column($out['columns'], 'key'), true)) {
                self::fail();
            }
            $out['columns'][] = ['key' => $key, 'label' => self::string($column['label'] ?? null, $key, 80),
                'width' => self::number($column['width'] ?? null, 30, 1, 1000)];
        }
        foreach (['indicatorsInSubject' => false, 'repeatHead' => true, 'borders' => true, 'showHead' => true,
            'headerBold' => true, 'areaBold' => true, 'valueBold' => true, 'subjectBold' => false] as $key => $default) {
            $out[$key] = (bool) ($raw[$key] ?? $default);
        }
        foreach (['borderColor' => '#183C67', 'headerColor' => '#FFFFFF', 'headerFill' => '#183C67',
            'areaFill' => '#EEE7FF', 'areaColor' => '#13264D'] as $key => $default) {
            $out[$key] = self::color($raw[$key] ?? null, $default);
        }
        $out['rowMode'] = self::pick($raw['rowMode'] ?? null, ['subjects', 'areas', 'areas-subjects'], 'subjects');
        $out['groupBy'] = self::pick($raw['groupBy'] ?? null, ['none', 'area'], 'none');
        $out['density'] = self::pick($raw['density'] ?? null, ['normal', 'compact'], 'normal');
        $out['groupLabel'] = self::string($raw['groupLabel'] ?? null, 'Área', 80);
        $out['borderStyle'] = self::pick($raw['borderStyle'] ?? null, ['solid', 'dashed', 'dotted', 'double'], 'solid');
        $out['borderWidth'] = self::number($raw['borderWidth'] ?? null, 1, 0, 5);
        $out['headerFont'] = self::pick($raw['headerFont'] ?? null, self::fonts(), 'Inter');
        // Real area results are supplied by the SIEE; editor demonstration weights are not academic data.
        $out['areaMethod'] = 'simple';
        $out['areaWeights'] = [];

        return $out;
    }

    public static function validate(array $raw, string $scope): array
    {
        Validator::make(['canvas' => $raw], [
            'canvas.pages' => ['required', 'array', 'min:1', 'max:12'], 'canvas.pages.*' => ['array'],
            // The HTTP middleware converts an empty optional title to null.
            'canvas.pages.*.title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'canvas.pages.*.format' => ['sometimes', 'string', 'in:carta,a4,oficio,a5'],
            'canvas.pages.*.orientation' => ['sometimes', 'string', 'in:portrait,landscape'],
            'canvas.pages.*.blocks' => ['present', 'array', 'max:100'], 'canvas.pages.*.blocks.*' => ['array'],
            'canvas.pages.*.blocks.*.content' => ['sometimes', 'array'],
            'canvas.pages.*.blocks.*.rule' => ['sometimes', 'array'],
            'canvas.pages.*.blocks.*.table' => ['sometimes', 'array'],
            'canvas.pages.*.blocks.*.table.columns' => ['sometimes', 'array'],
            'canvas.pages.*.blocks.*.table.columns.*' => ['array'],
            'canvas.sheet' => ['sometimes', 'array'], 'canvas.sheet.frame' => ['sometimes', 'array'],
            'canvas.sheet.footer' => ['sometimes', 'array'], 'canvas.sheet.footer.rule' => ['sometimes', 'array'],
            'canvas.sheet.numbering' => ['sometimes', 'array'], 'canvas.design' => ['sometimes', 'array'],
            'canvas.pagination' => ['sometimes', 'array'], 'canvas.designer' => ['sometimes', 'array'],
            'canvas.assets' => ['sometimes', 'array'],
        ])->validate();
        if (strlen(json_encode($raw, JSON_THROW_ON_ERROR)) > 1_500_000 || ! is_array($raw['pages'] ?? null)
            || count($raw['pages']) < 1 || count($raw['pages']) > 12) {
            self::fail();
        }
        $out = ['schema' => 2, 'layout' => 'canvas', 'scope' => $scope,
            'key' => self::string($raw['key'] ?? null, 'canvas-document', 80),
            'format' => self::pick($raw['format'] ?? null, ['carta', 'a4', 'oficio', 'a5'], 'carta'),
            'orientation' => self::pick($raw['orientation'] ?? null, ['portrait', 'landscape'], 'portrait'),
            'accent' => self::color($raw['accent'] ?? null, '#6A23FF'), 'pages' => [], 'assets' => [],
            'designer' => ['rows' => ($raw['designer']['rows'] ?? 2) === 1 ? 1 : 2],
            'pagination' => ['enabled' => true, 'margin' => self::number($raw['pagination']['margin'] ?? null, 44, 24, 90),
                'gap' => self::number($raw['pagination']['gap'] ?? null, 14, 0, 60), 'repeatHeader' => (bool) ($raw['pagination']['repeatHeader'] ?? true)]];
        $ids = [];
        foreach ($raw['pages'] as $index => $page) {
            foreach (['hidden', 'locked'] as $flag) {
                if (array_key_exists($flag, $page) && ! is_bool($page[$flag])) {
                    self::fail();
                }
            }
            if (! is_array($page['blocks'] ?? null) || count($page['blocks']) > 100) {
                self::fail();
            }
            $blocks = [];
            foreach ($page['blocks'] as $block) {
                $id = self::string($block['key'] ?? null, '', 80);
                if (! preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $id) || in_array($id, $ids, true)) {
                    self::fail();
                }
                $ids[] = $id;
                $type = self::pick($block['type'] ?? '', self::TYPES, 'text');
                $node = ['key' => $id, 'type' => $type, ...self::typography($block),
                    'name' => self::string($block['name'] ?? null, $type, 100),
                    'text' => self::string($block['text'] ?? null, '', 10000),
                    'x' => self::number($block['x'] ?? null, 44, 0, 2000), 'y' => self::number($block['y'] ?? null, 44, 0, 2000),
                    'w' => self::number($block['w'] ?? null, 400, 35, 2000), 'h' => self::number($block['h'] ?? null, 60, 4, 2000),
                    'fill' => self::color($block['fill'] ?? null, '#FFFFFF', true), 'accent' => self::color($block['accent'] ?? null, $out['accent']),
                    'radius' => self::number($block['radius'] ?? null, 0, 0, 70), 'padding' => self::number($block['padding'] ?? null, 12, 0, 60),
                    'lineHeight' => self::number($block['lineHeight'] ?? null, 1.5, 1, 3), 'letterSpacing' => self::number($block['letterSpacing'] ?? null, 0, -1, 8),
                    'hidden' => (bool) ($block['hidden'] ?? false), 'locked' => (bool) ($block['locked'] ?? false), 'repeat' => (bool) ($block['repeat'] ?? false),
                    'strike' => (bool) ($block['strike'] ?? false), 'captionBold' => (bool) ($block['captionBold'] ?? true),
                    'rule' => self::rule($block['rule'] ?? []), 'content' => []];
                if (isset($block['groupKey'])) {
                    $group = self::string($block['groupKey'], '', 80);
                    if (! preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $group)) {
                        self::fail();
                    }
                    $node['groupKey'] = $group;
                }
                $node['headerPart'] = (bool) ($block['headerPart'] ?? false);
                if (isset($block['appearance'])) {
                    $appearance = $block['appearance'];
                    if (! is_array($appearance)) {
                        self::fail();
                    }
                    $node['appearance'] = [
                        'header' => self::pick($appearance['header'] ?? null, ['center', 'side', 'editorial', 'band', 'compact', 'playful'], 'center'),
                        'table' => self::pick($appearance['table'] ?? null, ['grid', 'minimal', 'striped', 'soft'], 'grid'),
                        'signature' => self::pick($appearance['signature'] ?? null, ['ambos', 'rector', 'docente', 'none'], 'ambos'),
                        'headerInk' => self::color($appearance['headerInk'] ?? null, '#FFFFFF'),
                    ];
                }
                foreach (($block['content'] ?? []) as $key => $value) {
                    if (! in_array($key, ['headerTitle', 'headerBody', 'student', 'scaleTitle', 'scaleNote', 'scaleBody', 'observationTitle', 'attendance', 'signatureTeacher', 'signaturePrincipal', 'promotion', 'indicatorsTitle'], true)) {
                        self::fail();
                    }
                    $node['content'][$key] = self::string($value);
                }
                if ($type === 'field') {
                    $node['field'] = self::pick($block['field'] ?? null, self::FIELDS, 'student');
                }
                if ($type === 'image') {
                    $node['source'] = self::pick($block['source'] ?? null, ['asset', 'school-logo'], 'asset');
                    $node['asset'] = self::string($block['asset'] ?? null, '', 80);
                }
                if (in_array($type, ['table', 'grades'], true)) {
                    $node['table'] = self::table($block['table'] ?? []);
                }
                $blocks[] = $node;
            }
            $out['pages'][] = ['key' => 'page-'.($index + 1),
                'title' => self::string($page['title'] ?? null, '', 100),
                'hidden' => $page['hidden'] ?? false, 'locked' => $page['locked'] ?? false,
                'format' => self::pick($page['format'] ?? null, ['carta', 'a4', 'oficio', 'a5'], $out['format']),
                'orientation' => self::pick($page['orientation'] ?? null, ['portrait', 'landscape'], $out['orientation']),
                'blocks' => $blocks];
        }
        if (count($ids) > 300 || ! is_array($raw['assets'] ?? []) || count($raw['assets'] ?? []) > 15) {
            self::fail();
        }
        $totalBytes = 0;
        foreach (($raw['assets'] ?? []) as $key => $token) {
            if (! preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', (string) $key) || ! is_string($token)) {
                self::fail();
            }
            $file = StoredFilePublicToken::findForTenant((string) tenant()->getKey(), $token);
            if (! $file || ! str_contains($file->path, '/bulletin-templates/assets/') || ! in_array($file->mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                self::fail();
            }
            $totalBytes += $file->size;
            if ($totalBytes > 6_291_456) {
                self::fail();
            }
            $out['assets'][$key] = $token;
        }
        foreach ($out['pages'] as $page) {
            foreach ($page['blocks'] as $block) {
                if ($block['type'] === 'image' && ($block['source'] ?? 'asset') !== 'school-logo' && ! isset($out['assets'][$block['asset']])) {
                    self::fail();
                }
            }
        }
        $sheet = $raw['sheet'] ?? [];
        $frame = $sheet['frame'] ?? [];
        $out['sheet'] = ['background' => self::color($sheet['background'] ?? null, '#FFFFFF'),
            'frame' => [...self::rule($frame), 'inset' => self::number($frame['inset'] ?? null, 20, 0, 90), 'radius' => self::number($frame['radius'] ?? null, 9, 0, 70)]];
        foreach (['footer', 'numbering'] as $part) {
            $item = $sheet[$part] ?? [];
            $out['sheet'][$part] = [...self::typography($item), 'enabled' => (bool) ($item['enabled'] ?? true),
                'locked' => (bool) ($item['locked'] ?? false), 'free' => (bool) ($item['free'] ?? false),
                'text' => self::string($item['text'] ?? null, $part === 'numbering' ? 'Página {{page}} de {{pages}}' : '{{school}}'),
                'bottom' => self::number($item['bottom'] ?? null, 20, 0, 2000), 'width' => self::number($item['width'] ?? null, $part === 'footer' ? 480 : 180, 50, 2000),
                'height' => isset($item['height']) ? self::number($item['height'], 22, 22, 2000) : null];
            if ($part === 'footer') {
                $out['sheet'][$part]['left'] = self::number($item['left'] ?? null, 44, 0, 2000);
                $out['sheet'][$part]['rule'] = self::rule($item['rule'] ?? []);
            } else {
                $out['sheet'][$part]['right'] = self::number($item['right'] ?? null, 44, 0, 2000);
                $out['sheet'][$part]['start'] = (int) self::number($item['start'] ?? null, 1, 1, 9999);
                $out['sheet'][$part]['skipFirst'] = (bool) ($item['skipFirst'] ?? false);
            }
        }
        $design = $raw['design'] ?? [];
        $out['design'] = ['header' => self::pick($design['header'] ?? null, ['center', 'side', 'editorial', 'band', 'compact', 'visual', 'playful'], 'center'),
            'previewScale' => self::pick($design['previewScale'] ?? null, ['numeric', 'visual'], 'numeric'),
            'table' => self::pick($design['table'] ?? null, ['grid', 'minimal', 'striped', 'cards', 'classic', 'soft'], 'grid'),
            'legend' => self::pick($design['legend'] ?? null, ['inline', 'stacked'], 'stacked'),
            'signature' => self::pick($design['signature'] ?? null, ['ambos', 'rector', 'docente', 'none'], 'ambos'),
            'headerInk' => self::color($design['headerInk'] ?? null, '#FFFFFF')];

        return $out;
    }
}
