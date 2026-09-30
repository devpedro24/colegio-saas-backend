<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\DatosInstitucionales;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Realtime\TenantChannelName;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_suspending_a_user_revokes_an_existing_tenant_session(): void
    {
        [$tenant, $rectorToken, $targetId, $targetToken] = $this->schoolWithUser();
        $api = 'http://'.$tenant->slug.'.localhost/api';

        $this->withToken($targetToken)->getJson($api.'/me')->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($rectorToken)->putJson($api.'/usuarios/'.$targetId, [
            'status' => User::STATUS_SUSPENDED,
        ])->assertOk();

        $tenant->run(function () use ($targetId) {
            $user = User::findOrFail($targetId);
            $this->assertSame(User::STATUS_SUSPENDED, $user->status);
            $this->assertSame(0, $user->tokens()->count());
        });

        app('auth')->forgetGuards();
        $this->withToken($targetToken)->getJson($api.'/me')->assertUnauthorized();
        $this->postJson($api.'/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.TenantChannelName::tokenForId((string) $tenant->id),
            'socket_id' => '123.456',
        ])->assertUnauthorized();
    }

    public function test_status_guard_blocks_existing_websocket_token_even_if_status_changed_outside_api(): void
    {
        [$tenant, , $targetId, $targetToken] = $this->schoolWithUser();
        $tenant->run(fn () => User::findOrFail($targetId)->update(['status' => User::STATUS_INACTIVE]));

        app('auth')->forgetGuards();
        $this->withToken($targetToken)->postJson('http://'.$tenant->slug.'.localhost/api/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.TenantChannelName::tokenForId((string) $tenant->id),
            'socket_id' => '123.456',
        ])->assertUnauthorized();
        $tenant->run(fn () => $this->assertSame(0, User::findOrFail($targetId)->tokens()->count()));
    }

    public function test_platform_token_is_rejected_when_account_becomes_inactive(): void
    {
        $admin = User::create([
            'name' => 'Superadmin', 'email' => 'platform@test.test',
            'password' => 'Password!123', 'role' => 'superadmin', 'status' => User::STATUS_ACTIVE,
        ]);
        $token = $admin->createToken('platform')->plainTextToken;
        $admin->update(['status' => User::STATUS_INACTIVE]);

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_admin_password_change_and_deletion_revoke_user_tokens(): void
    {
        [$tenant, $rectorToken, $targetId] = $this->schoolWithUser();
        $api = 'http://'.$tenant->slug.'.localhost/api';
        app('auth')->forgetGuards();

        $this->withToken($rectorToken)->putJson($api.'/usuarios/'.$targetId, [
            'password' => 'NuevaClaveSegura!123',
        ])->assertOk();
        $tenant->run(fn () => $this->assertSame(0, User::findOrFail($targetId)->tokens()->count()));

        $tenant->run(fn () => User::findOrFail($targetId)->createToken('web'));
        app('auth')->forgetGuards();
        $this->withToken($rectorToken)->deleteJson($api.'/usuarios/'.$targetId)->assertOk();
        $tenant->run(fn () => $this->assertSame(0, User::withTrashed()->findOrFail($targetId)->tokens()->count()));
    }

    public function test_user_list_omits_temporary_password_but_explicit_endpoint_keeps_it_available(): void
    {
        [$tenant, $rectorToken, $targetId] = $this->schoolWithUser();
        $tenant->run(fn () => User::findOrFail($targetId)->update([
            'must_change_password' => true,
            'temporary_password' => 'TemporalSegura!123',
        ]));
        $api = 'http://'.$tenant->slug.'.localhost/api';

        $listed = $this->withToken($rectorToken)->getJson($api.'/usuarios')->assertOk()->json('data');
        $user = collect($listed)->firstWhere('email', 'docente@sesiones.test');
        $this->assertNotNull($user);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $user['url_token']);
        $this->assertSame($user['url_token'], $user['id']);
        $this->assertNotSame((string) $targetId, $user['id']);
        $this->assertArrayNotHasKey('temporary_password', $user);

        $this->getJson($api.'/usuarios/'.$targetId.'/temporal-password')
            ->assertOk()->assertJsonPath('password', 'TemporalSegura!123');
    }

    /** @return array{Tenant, string, int, string} */
    private function schoolWithUser(): array
    {
        $this->seed(DatabaseSeeder::class);
        ['tenant' => $tenant] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio sesiones', 'slug' => 'sesiones-'.uniqid(),
            'rector_email' => 'rector@sesiones.test',
        ]);
        $tenant->update(['status' => Tenant::STATUS_ACTIVE]);
        Storage::fake('tenant');
        Storage::disk('tenant')->put($tenant->id.'/branding/logo.png', 'logo');

        [$rectorToken, $targetId, $targetToken] = $tenant->run(function () {
            DatosInstitucionales::create([
                'nombre' => 'Colegio sesiones', 'nit' => '900123456-7',
                'resolucion_men' => '123 de 2026', 'direccion' => 'Calle 1',
                'telefono' => '6011234567', 'correo' => 'colegio@test.test',
            ]);
            $rector = User::where('email', 'rector@sesiones.test')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $target = User::create([
                'name' => 'Docente', 'email' => 'docente@sesiones.test',
                'password' => 'Password!123', 'role' => 'docente', 'status' => User::STATUS_ACTIVE,
            ]);
            $target->assignRole('docente');

            return [$rector->createToken('web')->plainTextToken, $target->id, $target->createToken('web')->plainTextToken];
        });

        return [$tenant, $rectorToken, $targetId, $targetToken];
    }
}
