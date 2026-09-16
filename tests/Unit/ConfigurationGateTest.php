<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConfigurationGate;
use PHPUnit\Framework\TestCase;

/**
 * Gate de operabilidad `configuring → active` (D-CONFIG-MIN).
 *
 * La parte que consulta la BD requiere contexto de tenant; aqui se cubre la
 * logica pura: los bloques del gate y el no-op sin contexto (seguridad).
 */
class ConfigurationGateTest extends TestCase
{
    public function test_define_exactamente_los_seis_bloques_de_la_config_minima(): void
    {
        $this->assertCount(6, ConfigurationGate::BLOCKS);

        foreach (['institucional', 'calendario', 'jornadas', 'escala', 'metodo', 'modelo'] as $block) {
            $this->assertArrayHasKey($block, ConfigurationGate::BLOCKS);
        }
    }

    public function test_sin_contexto_de_tenant_maybe_activate_no_hace_nada(): void
    {
        // Fuera de un colegio (tenant() === null) el gate debe ser un no-op.
        $this->assertNull(ConfigurationGate::maybeActivate(null));
    }

    public function test_solo_completa_si_estan_los_seis_bloques_y_todos_son_true(): void
    {
        $checks = array_fill_keys(array_keys(ConfigurationGate::BLOCKS), true);

        $this->assertTrue(ConfigurationGate::areBlocksComplete($checks));

        $checks['jornadas'] = false;
        $this->assertFalse(ConfigurationGate::areBlocksComplete($checks));

        unset($checks['jornadas']);
        $this->assertFalse(ConfigurationGate::areBlocksComplete($checks));
    }
}
