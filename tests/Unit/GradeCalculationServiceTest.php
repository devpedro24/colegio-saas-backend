<?php

namespace Tests\Unit;

use App\Services\GradeCalculationService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GradeCalculationServiceTest extends TestCase
{
    private function weighted(array $values, array $weights): array
    {
        return ['mode' => 'WEIGHTED_AVERAGE', 'inputs' => array_map(fn ($value, $weight) => ['value' => $value, 'weight' => $weight], $values, $weights)];
    }

    public function test_specification_examples_without_intermediate_rounding(): void
    {
        $service = new GradeCalculationService;
        $config = ['nota_minima' => '3', 'decimales' => 1, 'precision_calculo' => 8, 'redondeo' => 'HALF_UP'];
        $subject = $this->weighted(['4', '3.5', '5', '3.8'], ['30', '40', '10', '20']);
        $this->assertSame('3.86000000', $service->result($subject, $config)['raw_value']);
        $area = $this->weighted(['3.86', '4.2', '3.6', '4.5'], ['50', '20', '20', '10']);
        $this->assertSame('3.94000000', $service->result($area, $config)['raw_value']);
        $annual = $this->weighted(['3.94', '4.1', '3.7', '4.3'], ['20', '25', '25', '30']);
        $annual['inputs'][0] = ['node' => $area, 'weight' => '20'];
        $result = $service->result($annual, $config);
        $this->assertSame('4.02800000', $result['raw_value']);
        $this->assertSame('4.0', $result['display_value']);
        $this->assertTrue($result['aprobado']);
        $this->assertSame($annual, $result['trace']);
    }

    public function test_three_periods_on_a_hundred_point_scale_and_truncation(): void
    {
        $node = ['mode' => 'SIMPLE_AVERAGE', 'inputs' => [['value' => '80'], ['value' => '90'], ['value' => '99']]];
        $result = (new GradeCalculationService)->result($node, ['nota_minima' => '70', 'decimales' => 1, 'redondeo' => 'TRUNCATE']);
        $this->assertSame('89.6', $result['display_value']);
    }

    public function test_weights_must_total_one_hundred_and_missing_values_are_not_zero(): void
    {
        $this->expectException(ValidationException::class);
        (new GradeCalculationService)->evaluate($this->weighted(['4', '3'], ['33.33', '33.33']));
    }

    public function test_missing_weight_is_rejected_even_if_other_weights_total_one_hundred(): void
    {
        $this->expectException(ValidationException::class);
        (new GradeCalculationService)->evaluate(['mode' => 'WEIGHTED_AVERAGE', 'inputs' => [['value' => '4', 'weight' => '100'], ['value' => '2']]]);
    }

    public function test_recovery_keeps_original_argument_and_applies_rules(): void
    {
        $service = new GradeCalculationService;
        $this->assertSame('4', (string) $service->recover('2.5', '4', 'REPLACE', '3')->toBigDecimal());
        $this->assertSame('3.25', (string) $service->recover('2.5', '4', 'AVERAGE', '3')->toBigDecimal());
        $this->assertSame('3', (string) $service->recover('2.5', '4', 'MAX_PASSING_GRADE', '3')->toBigDecimal());
    }
}
