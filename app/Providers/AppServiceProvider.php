<?php

namespace App\Providers;

use App\Models\User;
use App\Support\AcademicTokenIndex;
use App\Support\RequestPerformance;
use App\Support\Storage\ClamAvScanner;
use App\Support\Storage\FileScanner;
use App\Support\Storage\NullScanner;
use App\Support\Storage\RejectingScanner;
use App\Tenancy\TenantDatabaseName;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        $this->app->scoped(AcademicTokenIndex::class);
        $this->app->scoped(RequestPerformance::class);
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
        app(\Illuminate\Notifications\ChannelManager::class)->extend('mail', fn ($app) => new \App\Notifications\SchoolMailChannel(
            new \App\Services\SchoolMailFactory, $app->make(\Illuminate\Mail\Markdown::class)
        ));
        RateLimiter::for('school-mail-test', fn (Request $request) => Limit::perMinute(3)->by((string) tenant()?->getKey()));
        if (config('performance.enabled')) {
            DB::listen(function (QueryExecuted $query): void {
                app(RequestPerformance::class)->record($query->time);
            });
        }
        Event::listen('eloquent.created: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;
            if ($model instanceof Model) {
                app(AcademicTokenIndex::class)->created($model);
            }
        });
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $base = rtrim(config('frontend.url'), '/');
            if (tenancy()->initialized) {
                $base = app(\App\Services\Ingreso\EnrollmentIntake::class)->baseUrl();
            }

            return $base.'/auth/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
        });
        RateLimiter::for('account-security', function (Request $request) {
            return Limit::perMinute(5)->by($request->getHost().'|'.$request->ip());
        });
        RateLimiter::for('ingreso-access', fn (Request $r) => [
            Limit::perMinute(5)->by('ingreso-ip|'.$r->getHost().'|'.$r->ip()),
            Limit::perHour(15)->by('ingreso-email|'.$r->getHost().'|'.hash('sha256', strtolower((string) $r->input('email')))),
        ]);
        RateLimiter::for('ingreso-upload', fn (Request $r) => [
            Limit::perMinute(5)->by($r->getHost().'|'.$r->ip()),
            Limit::perDay(100)->by($r->getHost().'|'.$r->ip()),
        ]);
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
