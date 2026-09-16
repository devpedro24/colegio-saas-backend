<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\PlatformAuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Facades\Tenancy;

/**
 * Capa de FALLBACK del log de auditoria append-only (RG-002, RN-LA-001..006).
 *
 * Cuando el INSERT directo de AuditLogger falla (pico de carga, lock, corte
 * transitorio), el evento se encola aqui para reintentarlo y NO perder la
 * evidencia. El atributo `attributes` es el payload del evento (mismo formato
 * que AuditLogger), con `target` = platform|tenant y `tenant_id` para volver a
 * inicializar el contexto del colegio.
 *
 * Si tras los reintentos sigue fallando, el job pasa a failed_jobs y se deja
 * un registro en los logs del framework: la evidencia NO se pierde en silencio.
 */
class PersistAuditLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** Reintentos con backoff progresivo: 10s, 60s, 5min. */
    public array $backoff = [10, 60, 300];

    /**
     * @param  array{target: 'platform'|'tenant', tenant_id?: ?string, attributes: array<string,mixed>}  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $target = $this->payload['target'];

        if ($target === 'tenant') {
            $this->persistTenant();

            return;
        }

        $this->persistPlatform();
    }

    private function persistPlatform(): void
    {
        PlatformAuditLog::create($this->payload['attributes']);
    }

    private function persistTenant(): void
    {
        $tenantId = $this->payload['tenant_id'] ?? null;

        if ($tenantId === null || ! class_exists(Tenant::class)) {
            throw new \RuntimeException('PersistAuditLog: falta tenant_id para el evento de colegio.');
        }

        $tenant = Tenancy::find($tenantId);

        if ($tenant === null) {
            throw new \RuntimeException("PersistAuditLog: el tenant {$tenantId} ya no existe.");
        }

        Tenancy::initialize($tenant);

        try {
            AuditLog::create($this->payload['attributes']);
        } finally {
            Tenancy::end();
        }
    }
}
