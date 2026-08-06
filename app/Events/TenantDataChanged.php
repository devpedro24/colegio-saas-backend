<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento genérico disparado cuando cualquier entidad del tenant (colegio)
 * es creada, actualizada o eliminada.
 *
 * El frontend se suscribe a `private-tenant.{id}` y escucha `.changed`
 * para invalidar las queries de TanStack según la entidad afectada.
 *
 * Uso en cualquier controller:
 *   TenantDataChanged::dispatch('sede', 'updated', $sede->nombre);
 */
class TenantDataChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $entity,
        public readonly string $action,
        public readonly ?string $entityName = null,
    ) {}

    public function broadcastOn(): array
    {
        $tenantId = tenant() ? (string) tenant()->getKey() : null;
        if ($tenantId === null) {
            return [];
        }

        return [new PrivateChannel("tenant.{$tenantId}")];
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    public function broadcastWith(): array
    {
        return [
            'entity' => $this->entity,
            'action' => $this->action,
            'name' => $this->entityName,
        ];
    }
}
