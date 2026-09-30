<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico disparado cuando cualquier entidad del tenant (colegio)
 * es creada, actualizada o eliminada.
 *
 * RealtimeServiceProvider agrupa este evento en application.changed
 * para invalidar las queries de TanStack según la entidad afectada.
 *
 * Uso en cualquier controller:
 *   TenantDataChanged::dispatch('sede', 'updated', $sede->nombre);
 */
class TenantDataChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly ?string $tenantId;

    public function __construct(
        public readonly string $entity,
        public readonly string $action,
        public readonly ?string $entityName = null,
    ) {
        $this->tenantId = tenant() ? (string) tenant()->getKey() : null;
    }

}
