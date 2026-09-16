<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PersistAuditLog;
use App\Models\PlatformAuditLog;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use LogicException;
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

        AuditLogger::platform(
            null,
            'UPDATE',
            'tenant',
            'abc-123',
            ['status' => 'configuring'],
            ['status' => 'active'],
            'Configuracion completa',
            'tenant-short-id',
        );

        Bus::assertDispatched(PersistAuditLog::class, function (PersistAuditLog $job): bool {
            $attributes = $job->payload['attributes'];

            return $attributes['valor_previo'] === ['status' => 'configuring']
                && $attributes['valor_nuevo'] === ['status' => 'active']
                && $attributes['motivo'] === 'Configuracion completa'
                && $attributes['tenant_id'] === 'tenant-short-id';
        });
    }

    public function test_modelo_de_auditoria_rechaza_update_y_delete(): void
    {
        $log = PlatformAuditLog::create([
            'accion' => 'CREATE',
            'recurso' => 'tenant',
        ]);

        try {
            $log->update(['accion' => 'UPDATE']);
            $this->fail('El modelo permitio modificar evidencia.');
        } catch (LogicException) {
            $this->assertSame('CREATE', $log->fresh()->accion);
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_fallback_tenant_preserva_antes_despues_motivo_y_suplantador(): void
    {
        Bus::fake();

        // La BD central no contiene audit_logs (esa tabla vive en el tenant),
        // por lo que aqui se activa deliberadamente el fallback.
        AuditLogger::tenant(
            null,
            'UPDATE',
            'archivo',
            '42',
            ['estado' => 'activo'],
            ['estado' => 'eliminado'],
            'Solicitud de soporte SEC-42',
            'admin@plataforma.test',
        );

        Bus::assertDispatched(PersistAuditLog::class, function (PersistAuditLog $job): bool {
            $attributes = $job->payload['attributes'];

            return $attributes['valor_previo'] === ['estado' => 'activo']
                && $attributes['valor_nuevo'] === ['estado' => 'eliminado']
                && $attributes['motivo'] === 'Solicitud de soporte SEC-42'
                && $attributes['impersonated_by'] === 'admin@plataforma.test'
                && ! array_key_exists('tenant_id', $attributes);
        });
    }
}
