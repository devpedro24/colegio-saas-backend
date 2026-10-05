<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Api\Academico\EscalaOpcionController;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Grupo;
use Brick\Math\BigRational;
use Illuminate\Database\Eloquent\Collection;

final class EscalaVisualService
{
    public function forGroup(Grupo $group): ?EscalaValorativa
    {
        $group->loadMissing('grado.nivel');
        $level = $group->grado->nivel->nivel_educativo;
        $scale = EscalaValorativa::with('opciones')->where('ano_lectivo_id', $group->ano_lectivo_id)
            ->where('nivel_educativo', $level)->where('tipo', EscalaValorativa::TIPO_IMAGENES)->first();
        if ($scale && $scale->opciones->count() < 2) {
            abort(422, 'La escala por imágenes de este nivel necesita al menos dos categorías configuradas.');
        }

        return $scale;
    }

    public function present(?EscalaValorativa $scale): array
    {
        return $scale ? ['tipo' => 'imagenes', 'nombre' => $scale->nombre,
            'opciones' => $scale->opciones->map(EscalaOpcionController::present(...))->values()->all()]
            : ['tipo' => 'numerica', 'opciones' => []];
    }

    public function decorate(array $result, Collection $options, ?string $provisional = null): array
    {
        $exact = $result['exact_value'] ?? null;
        if ($exact !== null) {
            $choice = $this->nearest((string) $exact, $options);
            $result['valoracion'] = EscalaOpcionController::present($choice);
            $result['aprobado'] = $choice->aprueba;
        }
        if ($provisional !== null) {
            $result['valoracion_provisional'] = EscalaOpcionController::present($this->nearest($provisional, $options));
        }

        return $result;
    }

    private function nearest(string $value, Collection $options): \App\Models\Academico\EscalaOpcion
    {
        // El motor conserva resultados exactos como fracciones (p. ej. 13/4).
        // Convertirlos a decimal aquí puede fallar o cambiar un empate.
        $target = BigRational::of($value);
        $best = null;
        $bestDistance = null;
        foreach ($options as $option) {
            $number = BigRational::of((string) $option->valor_equivalente);
            $distance = $target->minus($number)->abs();
            if ($best === null || $distance->isLessThan($bestDistance)
                || ($distance->isEqualTo($bestDistance)
                    && $number->isLessThan((string) $best->valor_equivalente))) {
                $best = $option;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}
