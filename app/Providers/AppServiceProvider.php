<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Storage\FileScanner;
use App\Support\Storage\NullScanner;
use App\Tenancy\TenantDatabaseName;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\DatabaseConfig;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pipeline de antivirus como ADAPTADOR (D-STORAGE): hoy un stub
        // aprobador; al enchufar ClamAV se cambia este binding (config/storage.php).
        $this->app->bind(FileScanner::class, function () {
            return match (config('storage.scanner')) {
                'clamav' => app(\App\Support\Storage\ClamAvScanner::class),
                default => new NullScanner(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Nombre de BD del colegio: tenant_<nombre>_<id corto> (RN-AI-001).
        DatabaseConfig::generateDatabaseNamesUsing(
            fn ($tenant) => TenantDatabaseName::for($tenant)
        );

        // El superadministrador suplantando un colegio PUEDE TODO (RN-RT-402):
        // el usuario sombra atraviesa cualquier gate de permiso de la ruta.
        Gate::before(fn (User $user) => $user->esSuperadminPlataforma() ? true : null);
    }
}