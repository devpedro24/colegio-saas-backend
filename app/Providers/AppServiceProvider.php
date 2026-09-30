<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Storage\ClamAvScanner;
use App\Support\Storage\FileScanner;
use App\Support\Storage\NullScanner;
use App\Support\Storage\RejectingScanner;
use App\Tenancy\TenantDatabaseName;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\DatabaseConfig;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\AcademicTokenIndex::class);
        $this->app->scoped(\App\Support\RequestPerformance::class);
        // En producción nunca se permite subir archivos sin escaneo real.
        $this->app->bind(FileScanner::class, function () {
            return match (config('storage.scanner')) {
                'clamav' => app(ClamAvScanner::class),
                'null' => $this->app->environment('production') ? new RejectingScanner : new NullScanner,
                default => new RejectingScanner,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('performance.enabled')) {
            \Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query): void {
                app(\App\Support\RequestPerformance::class)->record($query->time);
            });
        }
        \Illuminate\Support\Facades\Event::listen('eloquent.created: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;
            if ($model instanceof \Illuminate\Database\Eloquent\Model) {
                app(\App\Support\AcademicTokenIndex::class)->created($model);
            }
        });
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $base = rtrim(config('frontend.url'), '/');
            if (tenancy()->initialized) {
                $domain = tenant()->domains()->value('domain');
                if (! $domain) {
                    throw new \RuntimeException('El colegio no tiene dominio de acceso.');
                }
                $parts = parse_url($base);
                $base = ($parts['scheme'] ?? 'https').'://'.$domain.(isset($parts['port']) ? ':'.$parts['port'] : '');
            }

            return $base.'/auth/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
        });
        RateLimiter::for('account-security', function (Request $request) {
            return Limit::perMinute(5)->by($request->getHost().'|'.$request->ip());
        });
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(5)
            ->by($request->getHost().'|'.$request->user()?->id.'|'.$request->path()));
        RateLimiter::for('school-uploads', function (Request $request): array {
            $principal = (string) (tenant()?->getTenantKey() ?? 'central').'|'.$request->user()?->id;

            return [
                Limit::perMinute(5)->by('upload-minute|'.$principal),
                Limit::perDay(100)->by('upload-day|'.$principal),
            ];
        });
        // Nombre de BD del colegio: tenant_<nombre>_<id corto> (RN-AI-001).
        DatabaseConfig::generateDatabaseNamesUsing(
            fn ($tenant) => TenantDatabaseName::for($tenant)
        );

        // El superadministrador suplantando un colegio PUEDE TODO (RN-RT-402):
        // el usuario sombra atraviesa cualquier gate de permiso de la ruta.
        Gate::before(fn (User $user) => $user->esSuperadminPlataforma() ? true : null);
    }
}
