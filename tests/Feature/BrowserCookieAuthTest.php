<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Impersonation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Auth\BrowserAuthCookies;
use App\Support\Realtime\TenantChannelName;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class BrowserCookieAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_platform_cookie_is_host_only_http_only_and_requires_csrf_and_origin(): void
    {
        $admin = $this->admin();
        $login = $this->postJson('http://localhost/api/login', [
            'email' => $admin->email, 'password' => 'Password!123',
        ])->assertOk()->assertJsonMissingPath('token')->assertJsonStructure(['user', 'csrf_token']);

        $session = $this->cookie($login, BrowserAuthCookies::PLATFORM);
        $csrf = $this->cookie($login, BrowserAuthCookies::CSRF);
        $this->assertTrue($session->isHttpOnly());
        $this->assertFalse($csrf->isHttpOnly());
        $this->assertSame('/api', $session->getPath());
        $this->assertSame('/', $csrf->getPath());
        $this->assertNull($session->getDomain());
        $this->assertSame('lax', strtolower((string) $session->getSameSite()));
        $this->assertFalse($session->isSecure());
        $this->assertSame($csrf->getValue(), $login->json('csrf_token'));

        $this->withUnencryptedCookies([
            BrowserAuthCookies::PLATFORM => $session->getValue(),
            BrowserAuthCookies::CSRF => $csrf->getValue(),
        ]);
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/me')->assertOk()
            ->assertJsonPath('csrf_token', $csrf->getValue());
        $this->putJson('http://localhost/api/account/profile', ['name' => 'Changed'])
            ->assertStatus(419);
        $this->withHeader('Origin', 'http://attacker.test')
            ->withHeader('X-CSRF-Token', $csrf->getValue())
            ->putJson('http://localhost/api/account/profile', ['name' => 'Changed'])
            ->assertForbidden();
        $this->withHeader('Origin', 'http://localhost')
            ->withHeader('X-CSRF-Token', 'invalid')
            ->putJson('http://localhost/api/account/profile', ['name' => 'Changed'])
            ->assertStatus(419);
        $this->withHeader('X-CSRF-Token', $csrf->getValue())
            ->putJson('http://localhost/api/account/profile', ['name' => 'Changed'])
            ->assertOk();
        $this->assertSame('Changed', $admin->fresh()->name);

        $this->postJson('http://localhost/api/logout')->assertOk();
        $this->assertSame(0, $admin->tokens()->count());
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/me')->assertUnauthorized();
    }

    public function test_api_without_accept_header_returns_json_unauthorized(): void
    {
        $this->get('http://localhost/api/me')->assertUnauthorized()
            ->assertHeader('content-type', 'application/json');
    }

    public function test_https_login_marks_all_browser_cookies_secure(): void
    {
        $admin = $this->admin();
        $login = $this->postJson('https://localhost/api/login', [
            'email' => $admin->email, 'password' => 'Password!123',
        ])->assertOk();

        $this->assertTrue($this->cookie($login, BrowserAuthCookies::PLATFORM)->isSecure());
        $this->assertTrue($this->cookie($login, BrowserAuthCookies::CSRF)->isSecure());
    }

    public function test_revoking_an_old_bearer_does_not_clear_a_different_browser_cookie(): void
    {
        $admin = $this->admin();
        $oldToken = $admin->createToken('platform')->plainTextToken;
        $login = $this->postJson('http://localhost/api/login', [
            'email' => $admin->email, 'password' => 'Password!123',
        ])->assertOk();
        $this->withUnencryptedCookies([
            BrowserAuthCookies::PLATFORM => $this->cookie($login, BrowserAuthCookies::PLATFORM)->getValue(),
            BrowserAuthCookies::CSRF => $this->cookie($login, BrowserAuthCookies::CSRF)->getValue(),
        ]);
        app('auth')->forgetGuards();
        $logout = $this->withToken($oldToken)->postJson('http://localhost/api/logout')->assertOk();
        $this->assertFalse(collect($logout->baseResponse->headers->getCookies())
            ->contains(fn ($cookie) => $cookie->getName() === BrowserAuthCookies::PLATFORM));
        $this->assertSame(1, $admin->tokens()->count());
        $this->withHeader('Authorization', '');
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/me')->assertOk();
    }

    public function test_cross_site_login_is_rejected_when_origin_is_missing_but_fetch_metadata_identifies_it(): void
    {
        $admin = $this->admin();
        $this->withHeader('Sec-Fetch-Site', 'cross-site')
            ->postJson('http://localhost/api/login', [
                'email' => $admin->email, 'password' => 'Password!123',
            ])->assertForbidden();
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_tenant_cookie_authenticates_private_channel_only_with_csrf(): void
    {
        [$tenant, $password] = $this->school();
        $base = 'http://'.$tenant->slug.'.localhost/api';
        $login = $this->postJson($base.'/login', [
            'email' => 'rector@cookie.test', 'password' => $password,
        ])->assertOk()->assertJsonMissingPath('token');
        $session = $this->cookie($login, BrowserAuthCookies::TENANT);
        $csrf = $this->cookie($login, BrowserAuthCookies::CSRF)->getValue();

        $this->assertSame('/api', $session->getPath());
        $this->assertNull($session->getDomain());
        $this->assertSame(TenantChannelName::tokenForId((string) $tenant->id),
            $login->json('user.tenant_channel'));
        $this->withUnencryptedCookies([BrowserAuthCookies::TENANT => $session->getValue(),
            BrowserAuthCookies::CSRF => $csrf]);
        app('auth')->forgetGuards();
        $this->getJson($base.'/me')->assertOk()->assertJsonPath('csrf_token', $csrf);

        $broadcaster = Broadcast::connection('reverb');
        foreach (Broadcast::getChannels() as $name => $callback) {
            $broadcaster->channel($name, $callback, ['guards' => ['sanctum']]);
        }
        config(['broadcasting.default' => 'reverb']);
        $channel = ['channel_name' => 'private-tenant.'.TenantChannelName::tokenForId((string) $tenant->id),
            'socket_id' => '123.456'];
        $this->postJson($base.'/broadcasting/auth', $channel)->assertStatus(419);
        $this->withHeader('Origin', 'http://'.$tenant->slug.'.localhost')
            ->withHeader('X-CSRF-Token', $csrf)
            ->postJson($base.'/broadcasting/auth', $channel)->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_impersonation_cookie_is_bound_to_live_platform_session_and_can_be_restored(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->admin();
        [$tenant] = $this->school(false);
        $login = $this->postJson('http://localhost/api/login', [
            'email' => $admin->email, 'password' => 'Password!123',
        ])->assertOk();
        $platform = $this->cookie($login, BrowserAuthCookies::PLATFORM)->getValue();
        $csrf = $this->cookie($login, BrowserAuthCookies::CSRF)->getValue();
        $this->withUnencryptedCookies([BrowserAuthCookies::PLATFORM => $platform,
            BrowserAuthCookies::CSRF => $csrf]);
        $this->withHeader('Origin', 'http://localhost')
            ->withHeader('X-CSRF-Token', $csrf);
        app('auth')->forgetGuards();
        $start = $this->postJson('http://localhost/api/platform/impersonar', [
            'colegio_slug' => $tenant->slug,
        ])->assertOk()->assertJsonMissingPath('token')->assertJsonMissingPath('data.colegio.id')
            ->assertJsonPath('data.colegio.slug', $tenant->slug);
        $impersonation = $this->cookie($start, BrowserAuthCookies::IMPERSONATION)->getValue();
        $this->withUnencryptedCookies([BrowserAuthCookies::IMPERSONATION => $impersonation]);
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/platform/impersonar/estado')
            ->assertOk()->assertJsonPath('data.colegio.slug', $tenant->slug);
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/anos-lectivos')->assertOk();

        $broadcaster = Broadcast::connection('reverb');
        foreach (Broadcast::getChannels() as $name => $callback) {
            $broadcaster->channel($name, $callback, ['guards' => ['sanctum']]);
        }
        config(['broadcasting.default' => 'reverb']);
        $this->postJson('http://localhost/api/tenant-broadcasting/auth', [
            'channel_name' => 'private-tenant.'.TenantChannelName::tokenForId((string) $tenant->id),
            'socket_id' => '123.456',
        ])->assertOk()->assertJsonStructure(['auth']);

        tenancy()->end();
        app('auth')->forgetGuards();
        $this->postJson('http://localhost/api/platform/impersonar/salir')->assertOk();
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/platform/impersonar/estado')
            ->assertOk()->assertJsonPath('data.colegio', null);
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/anos-lectivos')->assertUnauthorized();
        $this->assertSame(0, Impersonation::whereNull('ended_at')->count());
    }

    public function test_revoking_platform_token_ends_existing_impersonation_cookie_access(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->admin();
        [$tenant] = $this->school(false);
        $login = $this->postJson('http://localhost/api/login', [
            'email' => $admin->email, 'password' => 'Password!123',
        ])->assertOk();
        $csrf = $this->cookie($login, BrowserAuthCookies::CSRF)->getValue();
        $this->withUnencryptedCookies([BrowserAuthCookies::PLATFORM => $this->cookie($login, BrowserAuthCookies::PLATFORM)->getValue(),
            BrowserAuthCookies::CSRF => $csrf]);
        $this->withHeader('Origin', 'http://localhost')->withHeader('X-CSRF-Token', $csrf);
        app('auth')->forgetGuards();
        $start = $this->postJson('http://localhost/api/platform/impersonar', [
            'colegio_slug' => $tenant->slug,
        ])->assertOk();
        $this->withUnencryptedCookies([BrowserAuthCookies::IMPERSONATION =>
            $this->cookie($start, BrowserAuthCookies::IMPERSONATION)->getValue()]);
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/anos-lectivos')->assertOk();
        $admin->tokens()->delete();
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/anos-lectivos')->assertUnauthorized();
    }

    public function test_platform_password_change_ends_and_revokes_shadow_sessions(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->admin();
        [$tenant] = $this->school(false);
        $login = $this->postJson('http://localhost/api/login', [
            'email' => $admin->email, 'password' => 'Password!123',
        ])->assertOk();
        $csrf = $this->cookie($login, BrowserAuthCookies::CSRF)->getValue();
        $this->withUnencryptedCookies([
            BrowserAuthCookies::PLATFORM => $this->cookie($login, BrowserAuthCookies::PLATFORM)->getValue(),
            BrowserAuthCookies::CSRF => $csrf,
        ])->withHeader('Origin', 'http://localhost')->withHeader('X-CSRF-Token', $csrf);
        app('auth')->forgetGuards();
        $started = $this->postJson('http://localhost/api/platform/impersonar', [
            'colegio_slug' => $tenant->slug,
        ])->assertOk();
        $impersonationCookie = $this->cookie($started, BrowserAuthCookies::IMPERSONATION)->getValue();
        $shadowToken = json_decode(Crypt::decryptString($impersonationCookie), true)['token'];
        $this->withUnencryptedCookies([BrowserAuthCookies::IMPERSONATION => $impersonationCookie]);
        $this->assertSame(1, Impersonation::whereNull('ended_at')->count());

        app('auth')->forgetGuards();
        $this->postJson('http://localhost/api/account/password', [
            'current_password' => 'Password!123', 'new_password' => 'NewPassword!123',
            'new_password_confirmation' => 'NewPassword!123',
        ])->assertOk();
        $this->assertSame(0, Impersonation::whereNull('ended_at')->count());
        $tenant->run(function (): void {
            $shadow = User::where('email', User::PLATFORM_SUPERADMIN_EMAIL)->firstOrFail();
            $this->assertSame(0, $shadow->tokens()->where('name', 'impersonation')->count());
        });

        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/anos-lectivos')->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($shadowToken)->withHeader('X-Tenant', (string) $tenant->id)
            ->getJson('http://localhost/api/anos-lectivos')->assertUnauthorized();
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@cookie.test',
            'password' => 'Password!123', 'role' => 'superadmin', 'status' => User::STATUS_ACTIVE]);
    }

    /** @return array{Tenant, string} */
    private function school(bool $seed = true): array
    {
        if ($seed) {
            $this->seed(DatabaseSeeder::class);
        }
        $result = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio Cookie', 'slug' => 'cookie-'.uniqid(),
            'rector_email' => 'rector@cookie.test',
        ]);
        $result['tenant']->update(['status' => Tenant::STATUS_ACTIVE]);

        return [$result['tenant'], $result['password']];
    }

    private function cookie($response, string $name): \Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        $this->fail('Missing cookie '.$name);
    }
}
