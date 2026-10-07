<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Periodo;
use Illuminate\Support\Facades\DB;

final class AulaContentPolicy
{
    public const DEFAULT_PERIOD_COLORS = [
        '1' => '#2563EB', '2' => '#0891B2', '3' => '#7C3AED', '4' => '#D97706',
    ];

    public const DEFAULT_PREINFORME_COLOR = '#64748B';

    public function configuration(): array
    {
        $row = DB::table('aula_politicas')->where('id', 1)->first();
        $savedColors = $row?->colores_periodos ? json_decode($row->colores_periodos, true) : [];

        return [
            'permitir_edicion_periodos_cerrados' => $row ? (bool) $row->permitir_edicion_periodos_cerrados : true,
            'colores_periodos' => array_replace(self::DEFAULT_PERIOD_COLORS, is_array($savedColors) ? $savedColors : []),
            'color_preinforme' => $row?->color_preinforme ?: self::DEFAULT_PREINFORME_COLOR,
        ];
    }

    public function assertEditable(Periodo $period): void
    {
        if ($period->estaCerrado()) {
            abort_unless($this->configuration()['permitir_edicion_periodos_cerrados'], 422,
                'El colegio bloqueó la edición de contenido del Aula en períodos cerrados.');
        }
    }
}
