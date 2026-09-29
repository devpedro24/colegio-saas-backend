<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** Ejecuta migraciones de un tenant nuevo y restaura el contexto aun si fallan. */
final class MigrateTenantDatabase
{
    public function __construct(private readonly Tenant $tenant) {}

    public function handle(): void
    {
        $originalTenant = tenant();
        try {
            $code = Artisan::call('tenants:migrate', ['--tenants' => [$this->tenant->getTenantKey()]]);
            if ($code !== 0) {
                throw new RuntimeException("Falló la migración del tenant {$this->tenant->getTenantKey()} (código {$code}).");
            }
        } finally {
            if ($originalTenant) {
                tenancy()->initialize($originalTenant);
            } else {
                tenancy()->end();
            }
        }
    }
}
