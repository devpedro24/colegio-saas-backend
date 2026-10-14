<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\EventMailOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;

final class SendEventMailBatch implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 240;
    public int $timeout = 180;
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public string $school)
    {
        $this->onConnection(config('events.connection'));
        $this->onQueue(config('events.queue'));
    }

    public function uniqueId(): string { return $this->school; }

    public function handle(): void
    {
        $school = Tenant::find($this->school);
        if (! $school || $school->status !== Tenant::STATUS_ACTIVE) return;
        $send = fn () => app(EventMailOutbox::class)->deliverBatch();
        if ((string) tenant()?->getTenantKey() === $this->school) $send();
        else $school->run($send);
    }
}
