<?php

namespace Tests\Unit;

use App\Services\BulletinPerformance;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BulletinPerformanceTest extends TestCase
{
    public function test_bands_use_one_decimal_display_and_cover_the_entire_scale(): void
    {
        $bands = BulletinPerformance::validate([
            ['nombre' => 'Alto', 'desde' => '4', 'hasta' => '5'],
            ['nombre' => 'Básico', 'desde' => '3', 'hasta' => '3.9'],
            ['nombre' => 'Bajo', 'desde' => '0', 'hasta' => '2.9'],
        ], '0', '5');
        $this->assertSame('0.0', $bands[0]['desde']);
        $this->assertSame('Básico', BulletinPerformance::label('3.0', $bands));
        $this->assertSame('Bajo', BulletinPerformance::label('2.9', $bands));
        $this->assertSame('Alto', BulletinPerformance::label('5.0', $bands));
    }

    public function test_gaps_are_rejected_instead_of_silently_classifying_students(): void
    {
        $this->expectException(ValidationException::class);
        BulletinPerformance::validate([
            ['nombre' => 'Bajo', 'desde' => '0', 'hasta' => '2.8'],
            ['nombre' => 'Alto', 'desde' => '3', 'hasta' => '5'],
        ], '0', '5');
    }

    public function test_legend_is_descending_without_changing_saved_labels_or_repeating_the_prefix(): void
    {
        $this->app->setLocale('es');
        $ranges = [
            ['nombre' => 'Bajo', 'desde' => '0.0', 'hasta' => '2.9'],
            ['nombre' => 'Desempeño Alto', 'desde' => '3.0', 'hasta' => '5.0'],
        ];
        $this->assertSame(['3.0 – 5.0: Desempeño Alto', '0.0 – 2.9: Desempeño Bajo'], BulletinPerformance::legend($ranges));
        $this->assertSame('Bajo', $ranges[0]['nombre']);
        $this->assertSame('Bajo', BulletinPerformance::label('2.8', $ranges));
        $this->app->setLocale('en');
        $this->assertSame(['3.0 – 5.0: Desempeño Alto', '0.0 – 2.9: Performance Bajo'], BulletinPerformance::legend($ranges));
    }
}
