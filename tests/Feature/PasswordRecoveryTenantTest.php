<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PasswordResetCode;
use App\Services\TenantProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryTenantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database = (string) config('database.connections.pgsql.database');
        if (getenv('RECOVERY_POSTGRES_TEST') !== '1'
            || ! preg_match('/^codex_recovery_test_[a-f0-9]{10}$/D', $database)) {
            $this->markTestSkipped('Run scripts/test-password-recovery-postgres.php for tenant isolation.');
        }
        $this->artisan('migrate:fresh', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
        \Stancl\Tenancy\DatabaseConfig::generateDatabaseNamesUsing(fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->getKey()), 0, 10));
        Notification::fake();
        $this->withHeader('Accept', 'application/json');
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) tenancy()->end();
        parent::tearDown();
    }

    public function test_codes_are_persistent_and_isolated_between_school_and_platform_requests(): void
    {
        $email = 'same@recovery.test';
        $schools = [];
        foreach (['first-recovery', 'second-recovery'] as $slug) {
            ['tenant' => $school] = app(TenantProvisioner::class)->provision(['name' => $slug, 'slug' => $slug, 'rector_email' => $email]);
            $school->update(['status' => Tenant::STATUS_ACTIVE]);
            $schools[] = $school;
        }
        [$first, $second] = $schools;
        $firstApi = 'http://'.$first->slug.'.localhost/api';
        $secondApi = 'http://'.$second->slug.'.localhost/api';
        $firstUser = $first->run(fn () => User::where('email', $email)->firstOrFail());
        $first->run(fn () => User::where('email', $email)->firstOrFail()->createToken('old'));
        $response = $this->postJson($firstApi.'/forgot-password/otp', ['email' => $email])->assertOk();
        $code = Notification::sent($firstUser, PasswordResetCode::class)->last()->code;
        $data = ['email' => $email, 'code' => $code, 'challenge' => $response->json('challenge'),
            'password' => 'NewSchoolPassword!123', 'password_confirmation' => 'NewSchoolPassword!123'];
        $this->postJson($secondApi.'/reset-password/otp', $data)->assertUnprocessable();
        $this->postJson('http://localhost/api/reset-password/otp', $data)->assertUnprocessable();
        // Different school may send its own code to the same email without consuming the first.
        $this->postJson($secondApi.'/forgot-password/otp', ['email' => $email])->assertOk();
        $this->postJson($firstApi.'/reset-password/otp', $data)->assertOk();
        $this->assertNull($first->fresh()->rector_temporary_password);
        $first->run(function () use ($email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue(Hash::check('NewSchoolPassword!123', $user->password));
            $this->assertFalse($user->must_change_password);
            $this->assertSame(0, $user->tokens()->count());
        });
        $second->run(fn () => $this->assertFalse(Hash::check('NewSchoolPassword!123', User::where('email', $email)->firstOrFail()->password)));
        $this->postJson($firstApi.'/reset-password/otp', $data)->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->postJson($firstApi.'/forgot-password', ['email' => $email])->assertOk();
        $link = Notification::sent($firstUser, ResetPassword::class)->last()->token;
        $this->postJson($secondApi.'/reset-password', [...$data, 'token' => $link])->assertUnprocessable();
        $this->postJson($firstApi.'/reset-password', [...$data, 'token' => $link])->assertOk();
    }
}
