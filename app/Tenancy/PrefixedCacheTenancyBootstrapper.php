<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/** Isolate tenant cache keys without requiring a cache store with tag support. */
final class PrefixedCacheTenancyBootstrapper implements TenancyBootstrapper
{
    private ?CacheManager $centralCache = null;
    private ?string $centralPrefix = null;
    private ?string $centralFilePath = null;
    private ?string $centralFileLockPath = null;

    public function __construct(private readonly Application $app) {}

    public function bootstrap(Tenant $tenant): void
    {
        $config = $this->app['config'];
        $this->centralCache = $this->app['cache'];
        $this->centralPrefix = (string) $config->get('cache.prefix');
        $this->centralFilePath = $config->get('cache.stores.file.path');
        $this->centralFileLockPath = $config->get('cache.stores.file.lock_path');

        $suffix = 'tenant_'.$tenant->getTenantKey();
        $config->set('cache.prefix', $this->centralPrefix.$suffix.'_');
        // Laravel's file and array stores do not use cache.prefix. File cache
        // needs its own directory; array cache gets a fresh manager per tenant.
        if ($this->centralFilePath !== null) {
            $config->set('cache.stores.file.path', rtrim($this->centralFilePath, '/\\').DIRECTORY_SEPARATOR.$suffix);
        }
        if ($this->centralFileLockPath !== null) {
            $config->set('cache.stores.file.lock_path', rtrim($this->centralFileLockPath, '/\\').DIRECTORY_SEPARATOR.$suffix);
        }

        $this->switchCache(fn () => new CacheManager($this->app));
    }

    public function revert(): void
    {
        $config = $this->app['config'];
        $config->set('cache.prefix', $this->centralPrefix);
        $config->set('cache.stores.file.path', $this->centralFilePath);
        $config->set('cache.stores.file.lock_path', $this->centralFileLockPath);

        $centralCache = $this->centralCache;
        $this->switchCache(fn () => $centralCache);
        $this->centralCache = null;
    }

    private function switchCache(callable $factory): void
    {
        Cache::clearResolvedInstances();
        $this->app->extend('cache', $factory);
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance('cache.psr6');
    }
}
