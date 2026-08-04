<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PersistAuditLog;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Auditoria append-only (RG-002, RN-LA-001..006): la operacion auditada NUNCA
 * se rompe. El INSERT directo persiste la evidencia; si falla, el evento se
 * ENCOLA como fallback (PersistAuditLog) para reintentarlo despues.
 */
class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_escribe_una_entrada_en_el_log_de_plataforma(): void
    {
        AuditLogger::platform(null, 'CREATE', 'tenant', 'abc-123', null, ['status' => 'active']);

        $this->assertDatabaseHas('platform_audit_logs', [
            'accion' => 'CREATE',
            'recurso' => 'tenant',
            'recurso_id' => 'abc-123',
        ]);
    }

    public function test_cuando_el_insert_falla_se_encola_el_fallback_para_reintentar(): void
    {
        Bus::fake();

        // Simula una caida del subsistema de auditoria: la tabla no existe.
        Schema::drop('platform_audit_logs');

        AuditLogger::platform(null, 'CREATE', 'tenant', 'abc-123');

        Bus::assertDispatched(PersistAuditLog::class);
    }
}