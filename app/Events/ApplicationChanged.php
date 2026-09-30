<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use App\Support\Realtime\TenantChannelName;

/** Invalidation only: never broadcast records, personal data or credentials. */
final class ApplicationChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly ?string $tenantId,
        public readonly array $resources,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->tenantId === null ? 'platform'
            : 'tenant.'.TenantChannelName::tokenForId($this->tenantId))];
    }

    public function broadcastAs(): string
    {
        return 'application.changed';
    }

    public function broadcastWith(): array
    {
        return ['resources' => $this->resources];
    }
}
