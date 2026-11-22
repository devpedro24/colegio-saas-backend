<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\LoginAppearance;
use App\Models\PlatformAuditLog;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LoginAppearanceService;
use App\Services\TenantProvisioner;
use App\Support\Auth\BrowserAuthCookies;
use App\Support\Storage\FileScanner;
use App\Support\Storage\NullScanner;
use App\Support\Storage\RejectingScanner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LoginAppearanceTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as private refreshWithTransactions; }

    public function refreshDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->refreshWithTransactions();
            return;
        }
        $database = (string) config('database.connections.pgsql.database');
        if (getenv('LOGIN_APPEARANCE_POSTGRES_TEST') !== '1'
            || ! preg_match('/^codex_login_images_test_[a-f0-9]{10}$/D', $database)) {
            throw new \RuntimeException('Use the isolated PostgreSQL login-image test runner.');
        }
        $this->artisan('migrate:fresh', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
        \Stancl\Tenancy\DatabaseConfig::generateDatabaseNamesUsing(
            fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->getKey()), 0, 10)
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tenant');
        Storage::fake('local');
        // Tenancy forgets disk instances on context changes; keep the fake root.
        config(['filesystems.disks.local.root' => Storage::disk('local')->path('')]);
        // Exercise the real binding and Laravel's parsed STORAGE_SCANNER=null.
        config(['storage.scanner' => null]);
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->withHeader('Accept', 'application/json');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) tenancy()->end();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create(['name' => 'Platform admin', 'email' => 'admin@images.test',
            'password' => 'Password!123', 'role' => 'superadmin', 'status' => 'active',
            'must_change_password' => false]);
    }

    private function school(string $slug): array
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Tenant integration requires scripts/test-login-appearance-postgres.php; existing tenant CHECK migrations require PostgreSQL.');
        }
        $this->seed(DatabaseSeeder::class);
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'School '.$slug, 'slug' => $slug, 'rector_email' => 'rector@'.$slug.'.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        $token = $school->run(function (): string {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            return $rector->createToken('web')->plainTextToken;
        });
        return [$school, $token];
    }

    private function central(): void
    {
        if (tenancy()->initialized) tenancy()->end();
        app('auth')->forgetGuards();
    }

    public function test_platform_image_upload_public_read_replace_and_reset_are_versioned(): void
    {
        $admin = $this->admin();
        $this->withToken($admin->createToken('platform')->plainTextToken);
        $base = 'http://localhost/api/platform/login-appearance';
        $this->getJson($base)->assertOk()->assertExactJson([
            'image_url' => null, 'version' => 'default', 'customized' => false,
        ]);
        $first = $this->post($base, ['version' => 'default', 'image' => UploadedFile::fake()->image('first.png', 800, 500)])
            ->assertOk()->assertJsonPath('customized', true)->assertJsonMissingPath('id')
            ->assertJsonMissingPath('path')->assertJsonMissingPath('stored_file_id');
        $url = $this->getJson('http://localhost/api/branding/login')->assertOk()->json('image_url');
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $this->get('http://localhost'.$url)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson($base)->assertUnauthorized();
        $this->get('http://localhost/api/branding/login/image?v=wrong')->assertNotFound();

        $this->withToken($admin->createToken('platform')->plainTextToken);
        app('auth')->forgetGuards();
        $second = $this->post($base, ['version' => $first->json('version'), 'image' => UploadedFile::fake()->image('second.jpg', 600, 400)])
            ->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles('login/platform'));
        $this->post($base, ['version' => $first->json('version'), 'image' => UploadedFile::fake()->image('stale.png', 600, 400)])
            ->assertStatus(409);
        $this->assertSame($second->json('version'), LoginAppearance::find('platform')->version);
        $this->get('http://localhost'.$url)->assertNotFound();
        $this->deleteJson($base, ['version' => $second->json('version')])->assertOk()
            ->assertJsonPath('image_url', null)->assertJsonPath('customized', false);
        $this->assertSame([], Storage::disk('local')->allFiles('login/platform'));
        $this->assertSame(3, PlatformAuditLog::where('recurso', 'config.login_appearance')->count());
    }

    public function test_account_photos_are_private_owned_and_isolated_between_platform_and_school(): void
    {
        $admin = $this->admin();
        $other = User::create(['name'=>'Other', 'email'=>'other@photo.test','password'=>'Password!123','role'=>'superadmin','status'=>'active','must_change_password'=>false]);
        $adminToken = $admin->createToken('platform')->plainTextToken;
        $this->withToken($adminToken);
        $saved = $this->post('http://localhost/api/account/photo', ['image'=>UploadedFile::fake()->image('personal.png',500,500)])->assertOk();
        $photo = $saved->json('user.avatar_url');
        $this->get('http://localhost'.$photo)->assertOk();
        $this->withToken($other->createToken('platform')->plainTextToken);
        app('auth')->forgetGuards();
        $this->get('http://localhost'.$photo)->assertNotFound();
        $this->flushHeaders(); app('auth')->forgetGuards();
        $this->get('http://localhost'.$photo)->assertUnauthorized();
        [$school, $rector] = $this->school('profile-photo');
        $this->withToken($rector)->withHeader('Accept','application/json');
        $api = 'http://'.$school->slug.'.localhost/api';
        $local = $this->post($api.'/account/photo', ['image'=>UploadedFile::fake()->image('rector.png',500,500)])->assertOk();
        $this->get('http://'.$school->slug.'.localhost'.$local->json('user.avatar_url'))->assertOk();
        $this->get('http://'.$school->slug.'.localhost'.$photo)->assertNotFound();
        $this->assertSame(1, StoredFile::where('tenant_id',$school->id)->count());
        $this->central(); $this->withToken($adminToken);
        $this->get('http://localhost'.$photo)->assertOk();
    }

    public function test_rector_and_platform_support_can_change_school_logo_and_read_contract(): void
    {
        $admin = $this->admin();
        [$school, $rector] = $this->school('school-logo-edit');
        $api = 'http://'.$school->slug.'.localhost/api';
        $this->withToken($rector);
        $this->post($api.'/school-branding/logo', ['logo'=>UploadedFile::fake()->image('logo.png',600,400)])->assertOk();
        $this->getJson($api.'/school-branding')->assertOk()->assertJsonPath('logo_url', fn ($url)=>str_starts_with($url,'/api/branding/logo?v='));
        $previousLogo = $this->getJson($api.'/school-branding')->json('logo_url');
        $this->app->instance(FileScanner::class, new RejectingScanner);
        $this->post($api.'/school-branding/logo', ['logo'=>UploadedFile::fake()->image('unsafe.png',500,500)])
            ->assertUnprocessable()->assertJsonValidationErrors('logo');
        $this->getJson($api.'/school-branding')->assertJsonPath('logo_url',$previousLogo);
        $this->assertSame(1, StoredFile::where('tenant_id',$school->id)->count());
        $this->app->instance(FileScanner::class, new NullScanner);
        $contract=$school->commercial;
        $this->getJson($api.'/subscription')->assertOk()->assertJsonPath('contract.students',$contract['students'])
            ->assertJsonPath('quote.monthly_total',$contract['quote']['monthly_total'])->assertJsonMissingPath('tenant_id');
        $teacher = $school->run(function () {
            $user=User::create(['name'=>'Teacher','email'=>'logo-teacher@test.test','password'=>'Password!123','status'=>'active','role'=>'docente']);
            $user->assignRole('docente'); return $user->createToken('web')->plainTextToken;
        });
        $this->withToken($teacher); app('auth')->forgetGuards();
        $this->getJson($api.'/subscription')->assertForbidden();
        $this->post($api.'/school-branding/logo', ['logo'=>UploadedFile::fake()->image('bad.png',500,500)])->assertForbidden();
        $this->central();
        $token=$school->run(function () {
            $user=User::create(['name'=>'Support','email'=>User::PLATFORM_SUPERADMIN_EMAIL,'password'=>'Password!123','status'=>'active','role'=>'rector','must_change_password'=>false]);
            $user->assignRole('rector'); return $user->createToken('impersonation');
        });
        \App\Models\Impersonation::create(['superadmin_id'=>$admin->id,'superadmin_email'=>$admin->email,'tenant_id'=>$school->id,
            'token_id'=>$token->accessToken->id,'started_at'=>now(),'expires_at'=>now()->addHour()]);
        $this->withToken($token->plainTextToken)->withHeader('X-Tenant',$school->id); app('auth')->forgetGuards();
        $this->post('http://localhost/api/school-branding/logo', ['logo'=>UploadedFile::fake()->image('support-logo.png',800,400)])->assertOk();
        $status=$this->getJson('http://localhost/api/school-branding')->assertOk();
        $this->get('http://localhost'.$status->json('logo_url'))->assertOk();
        $this->getJson('http://localhost/api/subscription')->assertOk();
    }

    public function test_local_null_scanner_saves_platform_and_school_images_through_real_binding(): void
    {
        $admin = $this->admin();
        [$school] = $this->school('local-image-config');
        $this->central();
        $this->app['env'] = 'local';
        $this->assertNull(config('storage.scanner'));
        $this->assertInstanceOf(NullScanner::class, app(FileScanner::class));
        $this->withToken($admin->createToken('platform')->plainTextToken);
        $this->post('http://localhost/api/platform/login-appearance', [
            'version' => 'default', 'image' => UploadedFile::fake()->image('platform-local.png', 600, 400),
        ])->assertOk()->assertJsonPath('customized', true);
        $this->post('http://localhost/api/colegios/'.$school->slug.'/login-appearance', [
            'version' => 'default', 'image' => UploadedFile::fake()->image('school-local.png', 800, 500),
        ])->assertOk()->assertJsonPath('customized', true);

        $this->assertSame(1, StoredFile::where('tenant_id', $school->getKey())->count());
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $platform = $this->getJson('http://localhost/api/branding/login')->assertOk()->json('image_url');
        $this->get('http://localhost'.$platform)->assertOk()->assertHeader('Content-Type', 'image/png');
        $schoolHost = 'http://'.$school->slug.'.localhost';
        $schoolImage = $this->getJson($schoolHost.'/api/branding/login')->assertOk()->json('image_url');
        $this->get($schoolHost.$schoolImage)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_production_without_scanner_rejects_upload_and_preserves_previous_image(): void
    {
        $admin = $this->admin();
        $this->withToken($admin->createToken('platform')->plainTextToken);
        $base = 'http://localhost/api/platform/login-appearance';
        $this->app['env'] = 'local';
        $saved = $this->post($base, ['version' => 'default',
            'image' => UploadedFile::fake()->image('existing.png', 600, 400)])->assertOk();

        $this->app['env'] = 'production';
        foreach ([null, 'null'] as $scanner) {
            config(['storage.scanner' => $scanner]);
            $this->post($base, ['version' => $saved->json('version'),
                'image' => UploadedFile::fake()->image('blocked.png', 700, 500)])
                ->assertUnprocessable()->assertJsonValidationErrors('image');
            $this->assertSame($saved->json('version'), LoginAppearance::find('platform')->version);
            $this->assertCount(1, Storage::disk('local')->allFiles('login/platform'));
        }
    }

    public function test_platform_upload_rejects_invalid_images_oversize_and_scanner_failure_preserving_previous(): void
    {
        $admin = $this->admin();
        $this->withToken($admin->createToken('platform')->plainTextToken);
        $base = 'http://localhost/api/platform/login-appearance';
        $first = $this->post($base, ['version' => 'default', 'image' => UploadedFile::fake()->image('valid.png', 600, 400)])->assertOk();
        foreach ([UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml'),
            UploadedFile::fake()->image('small.png', 20, 20),
            UploadedFile::fake()->image('large.png', 600, 400)->size(5121)] as $image) {
            $this->post($base, ['version' => $first->json('version'), 'image' => $image])
                ->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->app->instance(FileScanner::class, new RejectingScanner);
        $this->post($base, ['version' => $first->json('version'), 'image' => UploadedFile::fake()->image('rejected.png', 600, 400)])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame($first->json('version'), LoginAppearance::find('platform')->version);
        $this->assertCount(1, Storage::disk('local')->allFiles('login/platform'));
    }

    public function test_browser_management_uses_platform_cookie_csrf_and_public_branding_needs_no_session(): void
    {
        $admin = $this->admin();
        $this->withCredentials();
        $login = $this->postJson('http://localhost/api/login', [
            'email' => $admin->email, 'password' => 'Password!123',
        ])->assertOk();
        $cookies = collect($login->baseResponse->headers->getCookies())->keyBy->getName();
        $csrf = $cookies->get(BrowserAuthCookies::CSRF)->getValue();
        $this->withUnencryptedCookies([
            BrowserAuthCookies::PLATFORM => $cookies->get(BrowserAuthCookies::PLATFORM)->getValue(),
            BrowserAuthCookies::CSRF => $csrf,
        ]);
        app('auth')->forgetGuards();
        $base = 'http://localhost/api/platform/login-appearance';
        $this->getJson($base)->assertOk();
        $this->deleteJson($base, ['version' => 'default'])->assertStatus(419);
        $this->withHeader('Origin', 'http://localhost')->withHeader('X-CSRF-Token', $csrf);
        $saved = $this->post($base, ['version' => 'default', 'image' => UploadedFile::fake()->image('cookie.png', 600, 400)])
            ->assertOk();
        $this->withHeader('Origin', 'http://other.test')->deleteJson($base, ['version' => $saved->json('version')])
            ->assertForbidden();
        $this->flushHeaders();
        $this->withUnencryptedCookies([BrowserAuthCookies::PLATFORM => 'invalid', BrowserAuthCookies::CSRF => 'invalid']);
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/branding/login')->assertOk()->assertJsonPath('customized', true);
    }

    public function test_admin_school_picker_is_paginated_searchable_and_does_not_expose_internal_ids(): void
    {
        $admin = $this->admin();
        $this->withToken($admin->createToken('platform')->plainTextToken);
        for ($n = 0; $n < 51; $n++) {
            Tenant::withoutEvents(fn () => Tenant::create(['id' => 'image-picker-'.$n,
                'name' => 'School '.sprintf('%03d', $n), 'slug' => 'catalog-'.sprintf('%03d', $n),
                'plan' => 'estandar', 'status' => 'active']));
        }
        $base = 'http://localhost/api/platform/login-appearance/schools';
        $this->getJson($base)->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('next_page', 2)
            ->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.tenant_id');
        $this->getJson($base.'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('next_page', null);
        $this->getJson($base.'?q=catalog-050')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'catalog-050');
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $this->getJson($base)->assertUnauthorized();
    }

    public function test_rector_can_only_manage_own_school_and_platform_admin_can_manage_each_school(): void
    {
        $admin = $this->admin();
        [$first, $rectorToken] = $this->school('first-images');
        [$second] = $this->school('second-images');
        $firstApi = 'http://'.$first->slug.'.localhost/api';
        $this->central();
        $this->withToken($rectorToken);
        $saved = $this->post($firstApi.'/login-appearance', ['version' => 'default',
            'image' => UploadedFile::fake()->image('school.png', 600, 400)])->assertOk();
        $publicUrl = $this->getJson($firstApi.'/branding/login')->assertOk()->json('image_url');
        $this->flushHeaders();
        app('auth')->forgetGuards();
        $this->get('http://'.$first->slug.'.localhost'.$publicUrl)->assertOk();
        $this->central();
        $this->getJson('http://'.$second->slug.'.localhost/api/branding/login')->assertOk()
            ->assertJsonPath('image_url', null);
        $this->get('http://'.$second->slug.'.localhost'.$publicUrl)->assertNotFound();
        $this->central();
        $this->getJson('http://localhost/api/branding/login')->assertJsonPath('image_url', null);
        $this->withToken($rectorToken);
        app('auth')->forgetGuards();
        $this->post('http://'.$second->slug.'.localhost/api/login-appearance', ['version' => 'default',
            'image' => UploadedFile::fake()->image('forbidden.png', 600, 400)])->assertUnauthorized();

        $this->central();
        $this->withToken($admin->createToken('platform')->plainTextToken);
        $target = 'http://localhost/api/colegios/'.$first->slug.'/login-appearance';
        $read = $this->getJson($target)->assertOk()->assertJsonPath('version', $saved->json('version'));
        $this->get('http://localhost'.$read->json('image_url'))->assertOk();
        $changed = $this->post($target, ['version' => $saved->json('version'),
            'image' => UploadedFile::fake()->image('admin-change.jpg', 600, 400)])->assertOk();
        $this->assertSame(1, StoredFile::where('tenant_id', $first->getKey())->count());
        $this->assertSame(2, StoredFile::withTrashed()->where('tenant_id', $first->getKey())->count());
        $this->assertSame(1, PlatformAuditLog::where('recurso', 'config.login_appearance')
            ->where('tenant_id', $first->getKey())->count());
        $this->getJson('http://localhost/api/colegios/123/login-appearance')->assertNotFound();
        $this->getJson('http://localhost/api/colegios/missing-images/login-appearance')->assertNotFound();
        $this->deleteJson($target, ['version' => $changed->json('version')])->assertOk()->assertJsonPath('image_url', null);
    }

    public function test_teacher_with_configuration_permission_cannot_manage_login_or_platform_settings(): void
    {
        [$school] = $this->school('teacher-images');
        $token = $school->run(function (): string {
            $user = User::create(['name' => 'Teacher', 'email' => 'teacher@images.test', 'password' => 'Password!123',
                'role' => 'docente', 'status' => 'active', 'must_change_password' => false]);
            $user->assignRole('docente');
            $user->givePermissionTo('academico.configurar');
            return $user->createToken('web')->plainTextToken;
        });
        $this->withToken($token);
        foreach (['GET', 'POST', 'DELETE'] as $method) {
            $this->json($method, 'http://'.$school->slug.'.localhost/api/login-appearance', ['version' => 'default'])->assertForbidden();
        }
        $this->getJson('http://'.$school->slug.'.localhost/api/platform/login-appearance')->assertNotFound();
        $this->assertNull(app(LoginAppearanceService::class)->read($school));
    }

    public function test_upload_counts_towards_school_quota_and_scanner_failure_keeps_existing_image(): void
    {
        [$school, $token] = $this->school('quota-images');
        $this->withToken($token);
        $api = 'http://'.$school->slug.'.localhost/api/login-appearance';
        $first = $this->post($api, ['version' => 'default', 'image' => UploadedFile::fake()->image('first.png', 600, 400)])->assertOk();
        $this->assertGreaterThan(0, StoredFile::usedBytesFor($school->getKey()));
        $this->app->instance(FileScanner::class, new RejectingScanner);
        $this->post($api, ['version' => $first->json('version'), 'image' => UploadedFile::fake()->image('unsafe.png', 700, 500)])
            ->assertUnprocessable();
        $this->assertSame($first->json('version'), app(LoginAppearanceService::class)->read($school)->version);
        $this->assertSame(1, StoredFile::where('tenant_id', $school->getKey())->count());
        $this->app->instance(FileScanner::class, new NullScanner);
        $this->central();
        \App\Models\Plan::where('key', $school->plan)->update(['storage_gb' => 0]);
        $this->post($api, ['version' => $first->json('version'), 'image' => UploadedFile::fake()->image('no-space.png', 700, 500)])
            ->assertUnprocessable();
        $this->assertSame($first->json('version'), app(LoginAppearanceService::class)->read($school)->version);
    }
}
