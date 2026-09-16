<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TenantRbacSynchronizationException;
use App\Services\TenantRbacSynchronizer;
use PHPUnit\Framework\TestCase;

class RbacSynchronizationPolicyTest extends TestCase
{
    public function test_preserva_roles_obsoletos_para_no_romper_asignaciones(): void
    {
        $this->assertSame('preserve_assignments', TenantRbacSynchronizer::OBSOLETE_POLICY);
    }

    public function test_excepcion_conserva_el_resultado_detallado_para_compensacion(): void
    {
        $result = [
            'attempted' => 2,
            'synced' => ['tenant-ok'],
            'failed' => ['tenant-fail' => 'conexion rechazada'],
            'policy' => TenantRbacSynchronizer::OBSOLETE_POLICY,
        ];

        $exception = new TenantRbacSynchronizationException($result);

        $this->assertSame($result, $exception->result);
        $this->assertStringContainsString('tenant-fail', $exception->getMessage());
    }
}
