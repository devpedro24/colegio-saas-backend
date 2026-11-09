<?php

declare(strict_types=1);

namespace App\Services;

/** Fixed server designs. Only color and bounded text size may be changed. */
final class BulletinPreset
{
    public const KEYS = ['periodo-numerico', 'periodo-indicadores', 'final-numerico'];

    public static function design(string $key, string $accent = '#183C67', int $size = 12): array
    {
        abort_unless(in_array($key, self::KEYS, true), 404);
        $scope = $key === 'final-numerico' ? 'final' : 'periodo';
        $config = BulletinTemplateDesign::base($scope);
        $config['accent'] = $accent;
        $types = ['header', 'title', 'student', 'scale', 'table', ...($scope === 'final' ? ['promotion'] : ['observation']), 'signature'];
        $ys = [35, 155, 202, 278, 410, 760, 920];
        $blocks = [];
        foreach ($types as $index => $type) {
            $node = ['key' => 'preset-'.$type, 'type' => $type, 'x' => 44, 'y' => $ys[$index], 'w' => 728,
                'h' => [105, 30, 62, 110, 300, 100, 60][$index], 'font' => 'Inter',
                'fontSize' => $type === 'title' ? $size + 2 : $size, 'accent' => $accent, 'padding' => 0,
                'text' => $type === 'title' ? ($scope === 'final' ? 'INFORME ACADÉMICO FINAL · {{year}}' : 'INFORME ACADÉMICO · {{period}} · {{year}}') : ''];
            if ($type === 'table') {
                $columns = [['key' => 'subject', 'label' => 'Asignatura', 'width' => 55],
                    ['key' => 'grade', 'label' => 'Valoración', 'width' => 20], ['key' => 'performance', 'label' => 'Desempeño', 'width' => 25]];
                if ($key === 'periodo-indicadores') {
                    $columns = [['key' => 'subject', 'label' => 'Asignatura e indicadores', 'width' => 75], ['key' => 'grade', 'label' => 'Valoración', 'width' => 25]];
                }
                if ($scope === 'final') {
                    array_splice($columns, 1, 0, [['key' => 'periods', 'label' => 'Períodos', 'width' => 25]]);
                }
                $node['table'] = ['columns' => $columns, 'indicatorsInSubject' => $key === 'periodo-indicadores'];
            }
            $blocks[] = $node;
        }
        $config['canvas'] = ['key' => $key, 'schema' => 2, 'layout' => 'flow', 'format' => 'carta', 'orientation' => 'portrait',
            'accent' => $accent, 'pages' => [['blocks' => $blocks]]];
        return BulletinTemplateDesign::validate(['nombre' => 'Boletín · '.$key, 'ambito' => $scope, 'configuracion' => $config]);
    }
}
