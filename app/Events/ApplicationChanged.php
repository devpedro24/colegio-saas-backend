<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use App\Support\Realtime\TenantChannelName;

/** Invalidation only: never broadcast records, personal data or credentials. */
final class ApplicationChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly ?string $tenantId,
        public readonly array $resources,
    ) {
        $this->dontBroadcastToCurrentUser();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->tenantId === null ? 'platform'
            : 'tenant.'.TenantChannelName::tokenForId($this->tenantId))];
    }

    public function shouldBroadcastNow(): bool
    {
        // Preserve the active tenant in local synchronous execution. Production
        // uses the Redis queue, so the broadcaster is outside the HTTP request.
        return config('queue.default') === 'sync';
    }

    public function broadcastAs(): string
    {
        return 'application.changed';
    }

    public function broadcastQueue(): string
    {
        return (string) config('performance.realtime_queue', 'default');
    }

    public function broadcastWith(): array
    {
        return ['resources' => $this->resources];
    }
}
