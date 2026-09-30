<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\MetodoAprobacion;

final class SieeConfiguration
{
    public const DEFAULTS = [
        'usar_areas' => false, 'modo_area' => 'SIMPLE_AVERAGE', 'modo_asignatura' => 'WEIGHTED_AVERAGE',
        'modo_anual' => 'SIMPLE_AVERAGE', 'redondeo' => 'HALF_UP', 'precision_calculo' => 8,
        'recuperacion' => 'REPLACE', 'mostrar_final' => true, 'etiqueta_final' => 'Definitiva',
        'escala_id' => null, 'metodo_id' => null,
    ];

    public function resolve(AnoLectivo $year): array
    {
        $config = array_replace(self::DEFAULTS, $year->siee ?? []);
        $scale = EscalaValorativa::where('ano_lectivo_id', $year->id)->find($config['escala_id']);
        $method = MetodoAprobacion::where('ano_lectivo_id', $year->id)->find($config['metodo_id']);
        abort_unless($scale && $method && $scale->tipo === 'numerica', 422, 'Selecciona una escala numérica y un método de aprobación del año en la configuración SIEE.');

        return [...$config, 'valor_min' => $scale->valor_min, 'valor_max' => $scale->valor_max, 'decimales' => $scale->decimales, 'nota_minima' => $method->nota_minima];
    }
}
