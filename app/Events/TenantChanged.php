<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Algo cambio para un colegio concreto (lo inhabilitaron, le cambiaron el plan o
 * su RBAC). Se emite al canal privado `tenant.<id>` para que la sesion del rector
 * reaccione en vivo (recargar la matriz o ser expulsado si lo inhabilitaron).
 */
class TenantChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param string $tenantId UUID del colegio
     * @param string $reason   'disabled' | 'enabled' | 'plan' | 'rbac'
     */
    public function __construct(public string $tenantId, public string $reason) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.'.$this->tenantId)];
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason];
    }
}
