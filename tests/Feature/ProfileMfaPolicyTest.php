<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ProfileMfaPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) tenancy()->end();
        parent::tearDown();
    }

    public function test_superadmin_has_full_access_without_mfa_and_can_enable_or_disable_it(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'mfa@example.test', 'password' => 'Clave123!',
            'role' => 'superadmin', 'status' => 'active', 'must_change_password' => false]);
        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Clave123!'])->assertOk()
            ->assertJsonPath('user.mfa_required', false);
        $this->assertSame(['*'], $user->tokens()->latest('id')->first()->abilities);
        $this->withToken($login->json('token'))->getJson('/api/colegios')->assertOk();
        $old = $this->postJson('/api/mfa/setup', ['password' => 'Clave123!'])->assertOk()->json('secret');
        $secret = $this->postJson('/api/mfa/setup', ['password' => 'Clave123!'])->assertOk()->json('secret');
        $this->assertNotSame($old, $secret);
        $codes = $this->postJson('/api/mfa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Clave123!'])
            ->assertUnprocessable()->assertJsonPath('mfa_required', true);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Clave123!', 'code' => $codes[0]])->assertOk();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Clave123!', 'code' => $codes[0]])->assertUnprocessable();
        $this->postJson('/api/mfa/disable', ['password' => 'Clave123!', 'code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()->assertJsonPath('mfa_enabled', false);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Clave123!'])->assertOk();
    }

    public function test_legacy_enrollment_session_recovers_access(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'legacy@example.test', 'password' => 'Clave123!',
            'role' => 'superadmin', 'status' => 'active', 'must_change_password' => false]);
        $token = $user->createToken('mfa-enrollment', ['mfa:enroll'], now()->addMinutes(15));
        $this->withToken($token->plainTextToken)->getJson('/api/colegios')->assertOk();
        $this->assertSame('platform', $token->accessToken->fresh()->name);
        $this->assertSame(['*'], $token->accessToken->fresh()->abilities);
    }

    public function test_rector_moves_from_password_to_institution_onboarding_without_mfa(): void
    {
        $this->seed(DatabaseSeeder::class);
        ['tenant' => $tenant, 'password' => $password] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio', 'slug' => 'profile-'.uniqid(), 'rector_email' => 'rector@example.test',
        ]);
        $api = 'http://'.$tenant->slug.'.localhost/api';
        $login = $this->postJson($api.'/login', ['email' => 'rector@example.test', 'password' => $password])->assertOk()
            ->assertJsonPath('user.mfa_required', false);
        $this->withToken($login->json('token'));
        $this->getJson($api.'/onboarding/status')->assertOk();
        $this->postJson($api.'/account/password', ['current_password' => $password,
            'new_password' => 'Nueva123!', 'new_password_confirmation' => 'Nueva123!'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson($api.'/onboarding/status')->assertOk()->assertJsonPath('institution_required', true);
    }
}
