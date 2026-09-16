<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Periodo;
use App\Services\ConfigurationGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConfigurationGateCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('anos_lectivos', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('tipo_calendario');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedTinyInteger('num_periodos');
            $table->boolean('tiene_quinto_periodo')->default(false);
            $table->string('estado');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('periodos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ano_lectivo_id');
            $table->string('nombre');
            $table->unsignedTinyInteger('orden');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('peso', 5, 2)->nullable();
            $table->string('estado');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function test_calendario_exige_cantidad_continuidad_y_cobertura_total(): void
    {
        $ano = AnoLectivo::create([
            'nombre' => '2026',
            'tipo_calendario' => AnoLectivo::TIPO_A,
            'fecha_inicio' => '2026-02-01',
            'fecha_fin' => '2026-11-30',
            'num_periodos' => 2,
            'tiene_quinto_periodo' => false,
            'estado' => AnoLectivo::ESTADO_PLANIFICADO,
        ]);

        $primero = $ano->periodos()->create([
            'nombre' => 'Primero',
            'orden' => 1,
            'fecha_inicio' => '2026-02-01',
            'fecha_fin' => '2026-06-30',
            'estado' => Periodo::ESTADO_PLANIFICADO,
        ]);
        $segundo = $ano->periodos()->create([
            'nombre' => 'Segundo',
            'orden' => 2,
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-11-30',
            'estado' => Periodo::ESTADO_PLANIFICADO,
        ]);

        $this->assertTrue(ConfigurationGate::hasCompleteCalendar($ano));

        $primero->update(['fecha_fin' => '2026-06-29']);
        $this->assertFalse(ConfigurationGate::hasCompleteCalendar($ano));

        $primero->update(['fecha_fin' => '2026-06-30']);
        $segundo->update(['fecha_fin' => '2026-11-29']);
        $this->assertFalse(ConfigurationGate::hasCompleteCalendar($ano));

        $segundo->delete();
        $this->assertFalse(ConfigurationGate::hasCompleteCalendar($ano));
    }
}
