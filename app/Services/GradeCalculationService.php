<?php

declare(strict_types=1);

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Motor decimal: no usa floats ni redondea los nodos intermedios. */
final class GradeCalculationService
{
    public const VERSION = 'siee-1';

    public const MODES = ['SIMPLE_AVERAGE', 'WEIGHTED_AVERAGE', 'MANUAL'];

    /** Cada nodo contiene mode, inputs [{value|node, weight?, reference?}], manual?. */
    public function evaluate(array $node): BigRational
    {
        $mode = $node['mode'];
        if ($mode === 'MANUAL') {
            if (! isset($node['manual'])) {
                $this->invalid('Falta la nota manual.');
            }

            return BigRational::of((string) $node['manual']);
        }
        $inputs = $node['inputs'] ?? [];
        if ($inputs === []) {
            $this->invalid('Faltan notas para calcular el resultado.');
        }
        if ($mode === 'WEIGHTED_AVERAGE') {
            self::assertWeights(array_map(fn ($input) => $input['weight'] ?? null, $inputs));
        }
        if (! in_array($mode, self::MODES, true)) {
            $this->invalid('Modo de cálculo inválido.');
        }
        $sum = BigRational::of(0);
        foreach ($inputs as $input) {
            if (! isset($input['node']) && ! isset($input['value'])) {
                $this->invalid('Hay notas pendientes.');
            }
            $value = isset($input['node']) ? $this->evaluate($input['node']) : BigRational::of((string) $input['value']);
            $sum = $sum->plus($mode === 'WEIGHTED_AVERAGE' ? $value->multipliedBy((string) $input['weight'])->dividedBy(100) : $value);
        }

        return $mode === 'SIMPLE_AVERAGE' ? $sum->dividedBy(count($inputs)) : $sum;
    }

    public function result(array $node, array $config): array
    {
        $value = $this->evaluate($node);
        $rounding = ($config['redondeo'] ?? 'HALF_UP') === 'TRUNCATE' ? RoundingMode::Down : RoundingMode::HalfUp;
        $threshold = BigRational::of((string) $config['nota_minima']);
        $display = (string) $value->toScale((int) $config['decimales'], $rounding);

        return [
            'raw_value' => (string) $value->toScale((int) ($config['precision_calculo'] ?? 8), RoundingMode::HalfUp),
            'exact_value' => (string) $value->simplified(),
            'display_value' => $display,
            'aprobado' => $value->isGreaterThanOrEqualTo($threshold),
            'calculation_version' => self::VERSION,
            'trace' => $node,
        ];
    }

    public function recover(string $original, string $recovery, string $mode, string $passing, ?string $manual = null): BigRational
    {
        return match ($mode) {
            'REPLACE' => BigRational::of($recovery),
            'AVERAGE' => BigRational::of($original)->plus($recovery)->dividedBy(2),
            'MAX_PASSING_GRADE' => BigRational::of($recovery)->isGreaterThan($passing) ? BigRational::of($passing) : BigRational::of($recovery),
            'MANUAL' => $manual !== null ? BigRational::of($manual) : throw ValidationException::withMessages(['nota' => 'Falta la nota definitiva manual.']),
            default => throw ValidationException::withMessages(['recuperacion' => 'Regla de recuperación inválida.']),
        };
    }

    public static function assertWeights(array $weights): void
    {
        if ($weights === [] || in_array(null, $weights, true)) {
            throw ValidationException::withMessages(['pesos' => 'Cada elemento ponderado necesita un peso.']);
        }
        $sum = BigDecimal::of(0);
        foreach ($weights as $weight) {
            $decimal = BigDecimal::of((string) $weight);
            if ($decimal->isLessThan(0) || $decimal->isGreaterThan(100)) {
                throw ValidationException::withMessages(['pesos' => 'Los pesos deben estar entre 0 y 100.']);
            }
            $sum = $sum->plus($decimal);
        }
        if (! $sum->isEqualTo(100)) {
            throw ValidationException::withMessages(['pesos' => 'Los pesos deben sumar exactamente 100 %.']);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['notas' => $message]);
    }
}
