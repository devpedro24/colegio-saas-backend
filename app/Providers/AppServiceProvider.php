<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Impersonation\ImpersonationAccess;
use App\Support\Storage\ClamAvScanner;
use App\Support\Storage\FileScanner;
use App\Support\Storage\NullScanner;
use App\Tenancy\TenantDatabaseName;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Stancl\Tenancy\DatabaseConfig;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FileScanner::class, function () {
            return match (config('storage.scanner')) {
                'clamav' => app(ClamAvScanner::class),
                default => new NullScanner,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email', '')));
            $tenantKey = function_exists('tenant') && tenant() !== null
                ? 'tenant:'.tenant()->getKey()
                : 'central:'.$request->getHost();
            $identity = hash('sha256', $tenantKey.'|'.$email);

            return [
                Limit::perMinute(max(1, (int) config('security.login.attempts_per_minute', 5)))
                    ->by('login:identity:'.$identity),
                Limit::perMinute(max(1, (int) config('security.login.attempts_per_ip_per_minute', 30)))
                    ->by('login:ip:'.$tenantKey.'|'.$request->ip()),
            ];
        });

        // Nombre de BD del colegio: tenant_<nombre>_<id corto> (RN-AI-001).
        DatabaseConfig::generateDatabaseNamesUsing(
            fn ($tenant) => TenantDatabaseName::for($tenant)
        );

        // Solo una identidad sombra con token y sesion de suplantacion vigentes
        // atraviesa los gates del tenant. El email por si solo nunca da privilegios.
        Gate::before(fn (User $user) => app(ImpersonationAccess::class)->sessionFor($user) !== null
            ? true
            : null);
    }
}
