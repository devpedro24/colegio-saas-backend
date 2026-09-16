<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\InitializeTenancyByDomainOrSubdomain;
use App\Models\User;
use App\Support\Tenancy\CentralDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_se_limita_por_cuenta_aunque_cambie_la_ip(): void
    {
        config()->set('security.login.attempts_per_minute', 3);

        $user = User::create([
            'name' => 'Admin',
            'email' => 'rate-limit@plataforma.test',
            'password' => Hash::make('Correcta123!'),
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => false,
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$attempt}"])->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'Incorrecta123!',
            ])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Incorrecta123!',
        ])->assertTooManyRequests();
    }

    public function test_token_de_login_tiene_expiracion_configurable(): void
    {
        config()->set('sanctum.expiration', 45);
        $user = User::create([
            'name' => 'Admin',
            'email' => 'expiry@plataforma.test',
            'password' => Hash::make('Correcta123!'),
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Correcta123!',
        ])->assertOk()->assertJsonStructure(['token', 'expires_at']);

        $expiresAt = $user->fresh()->tokens()->sole()->expires_at;
        $this->assertNotNull($expiresAt);
        $this->assertTrue($expiresAt->between(now()->addMinutes(44), now()->addMinutes(46)));
    }

    public function test_echo_de_suplantacion_inicializa_tenant_antes_de_autenticar(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($candidate): bool => $candidate->uri() === 'api/tenant-broadcasting/auth'
                && in_array('POST', $candidate->methods(), true),
        );

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertContains(InitializeTenancyByRequestData::class, $middleware);
        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains('impersonation', $middleware);
        $this->assertLessThan(
            array_search('auth:sanctum', $middleware, true),
            array_search(InitializeTenancyByRequestData::class, $middleware, true),
        );
    }

    public function test_login_tenant_usa_el_mismo_rate_limiter_endurecido(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($candidate): bool {
            $middleware = $candidate->gatherMiddleware();

            return $candidate->uri() === 'api/login'
                && in_array('POST', $candidate->methods(), true)
                && in_array(InitializeTenancyByDomainOrSubdomain::class, $middleware, true);
        });

        $this->assertNotNull($route);
        $this->assertContains('throttle:login', $route->gatherMiddleware());
    }

    public function test_todas_las_rutas_tenant_autenticadas_validan_suplantacion_si_esta_presente(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($candidate): bool {
            $middleware = $candidate->gatherMiddleware();

            return $candidate->uri() === 'api/me'
                && in_array('GET', $candidate->methods(), true)
                && in_array(InitializeTenancyByDomainOrSubdomain::class, $middleware, true);
        });

        $this->assertNotNull($route);
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('impersonation:when-present', $route->gatherMiddleware());
    }

    public function test_url_firmada_de_storage_usa_host_canonico_y_nombres_unicos(): void
    {
        $storageRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->uri() === 'api/storage/{file}/download')
            ->values();
        $canonical = CentralDomains::canonicalDomain(
            (array) config('tenancy.central_domains'),
            (string) config('app.url'),
        );

        $this->assertCount(count(config('tenancy.central_domains')), $storageRoutes);
        $this->assertSame($storageRoutes->count(), $storageRoutes->pluck('action.as')->unique()->count());
        $this->assertSame($canonical, Route::getRoutes()->getByName('storage.file')?->getDomain());

        $url = URL::temporarySignedRoute(
            'storage.file',
            now()->addMinute(),
            ['file' => 1, 'tenant' => 'tenant-test'],
        );

        $this->assertSame($canonical, parse_url($url, PHP_URL_HOST));
    }
}
