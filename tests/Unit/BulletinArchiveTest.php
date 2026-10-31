<?php

namespace Tests\Unit;

use App\Services\BulletinArchive;
use Tests\TestCase;

class BulletinArchiveTest extends TestCase
{
    public function test_download_name_uses_surnames_period_grade_group_and_frozen_year(): void
    {
        $snapshot = ['estudiante' => ['nombre' => 'Salomé Álvarez Arias', 'grado' => 'Primero', 'grupo' => '01A'],
            'periodo' => ['nombre' => 'Cuarto período'], 'ano' => '2026'];
        $this->assertSame('Álvarez_Arias_Salomé_Cuarto_período_Primero_01A_2026.pdf', BulletinArchive::filename($snapshot));
        $snapshot['estudiante']['nombre_lista'] = 'De la Cruz Ríos Ana María';
        $snapshot['periodo'] = null;
        $this->assertSame('De_la_Cruz_Ríos_Ana_María_Final_Primero_01A_2026.pdf', BulletinArchive::filename($snapshot));
        $snapshot['estudiante']['nombre_lista'] = '../Ana "Nueva" / Ríos';
        $this->assertStringNotContainsString('/', BulletinArchive::filename($snapshot));
        $this->assertStringNotContainsString('"', BulletinArchive::filename($snapshot));
    }

    public function test_long_unicode_names_fit_the_filesystem_without_invalid_utf8(): void
    {
        $snapshot = ['estudiante' => ['nombre' => 'Ana Ríos', 'nombre_lista' => str_repeat('Álvarez ', 30),
            'grado' => str_repeat('Décimo ', 20), 'grupo' => str_repeat('Ñ ', 60)],
            'periodo' => ['nombre' => str_repeat('Período ', 20)], 'ano' => '2026'];
        $filename = BulletinArchive::filename($snapshot);
        $this->assertLessThanOrEqual(240, strlen($filename));
        $this->assertTrue(mb_check_encoding($filename, 'UTF-8'));
        $this->assertStringEndsWith('_2026.pdf', $filename);
    }
}
