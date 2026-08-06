<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SedeCreada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $sedeId,
        public readonly string $colegioId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->colegioId}")];
    }

    public function broadcastAs(): string
    {
        return 'sede.creada';
    }
}
