<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

final class TenantFilesystemBootstrapper extends FilesystemTenancyBootstrapper
{
    public function bootstrap(Tenant $tenant)
    {
        $root = base_path('storage/app/tenants/'.StorageService::tenantFolder($tenant).'/sistema');
        // Keep private disk paths stable when a school changes its display name.
        $existing = glob(base_path('storage/app/tenants/*_'.$tenant->getTenantKey().'/sistema'), GLOB_ONLYDIR);
        $root = $existing[0] ?? $root;
        $this->app['config']['tenancy.filesystem.root_override.local'] = $root.'/local';
        $this->app['config']['tenancy.filesystem.root_override.public'] = $root.'/public';
        parent::bootstrap($tenant);
    }
}
