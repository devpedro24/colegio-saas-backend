<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use Database\Seeders\RbacSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Applies catalog/plan changes before asking open school sessions to refresh /me. */
final class SynchronizeTenantPermissions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $tenantId) {}

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || in_array($tenant->status, [Tenant::STATUS_PROVISIONING, Tenant::STATUS_DELETED], true)) return;
        // The seeder preserves the rector's configurable grants and emits the rbac event.
        $tenant->run(fn () => (new RbacSeeder())->run());
    }
}
