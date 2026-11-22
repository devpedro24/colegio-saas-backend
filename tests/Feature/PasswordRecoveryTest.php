<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\PlatformAuditLog;
use App\Notifications\PasswordResetCode;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->withHeader('Accept', 'application/json');
    }

    private function user(string $email = 'recovery@test.test', string $status = 'active'): User
    {
        return User::create(['name' => 'Recovery', 'email' => $email, 'password' => 'OldPassword!123',
            'role' => 'superadmin', 'status' => $status, 'must_change_password' => false]);
    }

    private function requestCode(User $user): array
    {
        $response = $this->postJson('/api/forgot-password/otp', ['email' => $user->email])->assertOk()
            ->assertJsonMissingPath('code')->assertJsonMissingPath('token')->assertJsonMissingPath('user')
            ->assertJsonMissingPath('id')->assertJsonPath('expires_in', 600)->assertJsonPath('resend_after', 60);
        $notification = Notification::sent($user, PasswordResetCode::class)->last();
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/D', $notification->code);
        return ['email' => $user->email, 'challenge' => $response->json('challenge'), 'code' => $notification->code,
            'password' => 'NewPassword!123', 'password_confirmation' => 'NewPassword!123'];
    }

    public function test_otp_is_single_use_revokes_sessions_and_never_exposes_the_code(): void
    {
        $user = $this->user();
        $user->createToken('old');
        $data = $this->requestCode($user);
        $cacheValues = \Illuminate\Support\Facades\DB::table('cache')->pluck('value')->implode(' ');
        $this->assertStringNotContainsString($data['code'], $cacheValues);
        $this->postJson('/api/reset-password/otp', [...$data, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/reset-password/otp', $data)->assertOk()->assertJsonMissingPath('token');
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        Notification::assertSentTo($user, \App\Notifications\PasswordWasReset::class);
        $this->postJson('/api/reset-password/otp', $data)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertStringNotContainsString($data['code'], PlatformAuditLog::all()->toJson());
    }

    public function test_invalid_codes_are_limited_to_five_attempts_even_with_the_correct_code_afterwards(): void
    {
        $data = $this->requestCode($this->user());
        $wrong = $data['code'] === '000000' ? '111111' : '000000';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/reset-password/otp', [...$data, 'code' => $wrong])->assertUnprocessable();
            if ($attempt === 3) $this->travel(61)->seconds(); // Cross the IP limiter's minute without resetting attempts.
        }
        $this->postJson('/api/reset-password/otp', $data)->assertUnprocessable();
    }

    public function test_expired_codes_are_rejected_and_resending_invalidates_previous_challenge(): void
    {
        $user = $this->user();
        $data = $this->requestCode($user);
        $this->postJson('/api/forgot-password/otp', ['email' => $user->email])->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $new = $this->requestCode($user);
        $this->postJson('/api/reset-password/otp', $data)->assertUnprocessable();
        $this->travel(601)->seconds();
        $this->postJson('/api/reset-password/otp', $new)->assertUnprocessable();
        $this->assertTrue(Hash::check('OldPassword!123', $user->fresh()->password));
    }

    public function test_unknown_inactive_and_shadow_accounts_receive_the_same_public_contract_without_email(): void
    {
        $active = $this->user();
        $known = $this->requestCode($active);
        $disabled = $this->user('disabled@test.test', 'inactive');
        $shadow = $this->user(User::PLATFORM_SUPERADMIN_EMAIL);
        foreach (['unknown@test.test', $disabled->email, $shadow->email] as $email) {
            $this->travel(61)->seconds();
            $response = $this->postJson('/api/forgot-password/otp', ['email' => $email])->assertOk()
                ->assertJsonStructure(['message', 'challenge', 'expires_in', 'resend_after']);
            $this->assertSame(24, strlen($response->json('challenge')));
            $this->postJson('/api/reset-password/otp', [...$known, 'email' => $email, 'challenge' => $response->json('challenge')])
                ->assertUnprocessable();
        }
        Notification::assertCount(1);
    }

    public function test_code_is_bound_to_account_and_to_current_credentials(): void
    {
        $user = $this->user();
        $data = $this->requestCode($user);
        $other = $this->user('other@test.test');
        $this->postJson('/api/reset-password/otp', [...$data, 'email' => $other->email])->assertUnprocessable();
        $user->update(['password' => 'ChangedElsewhere!123']);
        $this->postJson('/api/reset-password/otp', $data)->assertUnprocessable();
    }

    public function test_using_otp_invalidates_link_and_using_link_invalidates_otp(): void
    {
        $user = $this->user();
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $link = Notification::sent($user, ResetPassword::class)->last()->token;
        $this->postJson('/api/forgot-password/otp', ['email' => $user->email])->assertStatus(429);
        $this->travel(61)->seconds();
        $data = $this->requestCode($user);
        $this->postJson('/api/reset-password/otp', $data)->assertOk();
        $this->postJson('/api/reset-password', [...$data, 'token' => $link])->assertUnprocessable();
        $this->travel(61)->seconds();
        $data = $this->requestCode($user);
        $this->travel(61)->seconds();
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $link = Notification::sent($user, ResetPassword::class)->last()->token;
        $this->postJson('/api/reset-password', [...$data, 'token' => $link])->assertOk();
        $this->postJson('/api/reset-password/otp', $data)->assertUnprocessable();
    }

    public function test_mail_failure_does_not_disclose_account_or_provider_details(): void
    {
        $user = $this->user();
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('provider credentials must not appear'));
        $response = $this->postJson('/api/forgot-password/otp', ['email' => $user->email])->assertOk()
            ->assertJsonMissingPath('code');
        $this->assertStringNotContainsString('credentials', $response->getContent());
        $this->postJson('/api/reset-password/otp', ['email' => $user->email, 'code' => '123456',
            'challenge' => $response->json('challenge'), 'password' => 'NewPassword!123', 'password_confirmation' => 'NewPassword!123'])
            ->assertUnprocessable();
    }

    public function test_recovery_link_expires_after_thirty_minutes(): void
    {
        $user = $this->user();
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $token = Notification::sent($user, ResetPassword::class)->last()->token;
        $this->travel(1801)->seconds();
        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $token,
            'password' => 'NewPassword!123', 'password_confirmation' => 'NewPassword!123'])->assertUnprocessable();
    }

    public function test_recovery_requests_have_an_hourly_account_limit_across_ip_windows(): void
    {
        $user = $this->user();
        for ($n = 0; $n < 5; $n++) {
            $this->requestCode($user);
            $this->travel(61)->seconds();
        }
        $this->postJson('/api/forgot-password/otp', ['email' => $user->email])->assertStatus(429);
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(429);
    }

    public function test_platform_reset_closes_existing_school_support_sessions(): void
    {
        $user = $this->user();
        $school = \App\Models\Tenant::withoutEvents(fn () => \App\Models\Tenant::create([
            'id' => 'recovery-support-school', 'slug' => 'recovery-support-school', 'name' => 'Recovery school', 'plan' => 'estandar', 'status' => 'active']));
        $session = \App\Models\Impersonation::create(['superadmin_id' => $user->id, 'superadmin_email' => $user->email,
            'tenant_id' => $school->id, 'token_id' => 1, 'started_at' => now(), 'expires_at' => now()->addHour()]);
        $data = $this->requestCode($user);
        $this->postJson('/api/reset-password/otp', $data)->assertOk();
        $this->assertNotNull($session->fresh()->ended_at);
    }

    public function test_browser_recovery_ignores_stale_session_cookies_but_rejects_cross_origin_requests(): void
    {
        $user = $this->user();
        $this->withCredentials()->withUnencryptedCookies([
            \App\Support\Auth\BrowserAuthCookies::PLATFORM => 'stale-session',
            \App\Support\Auth\BrowserAuthCookies::CSRF => 'stale-csrf',
        ])->withHeader('Origin', 'http://untrusted.test');
        $this->postJson('/api/forgot-password/otp', ['email' => $user->email])->assertForbidden();
        $this->withHeader('Origin', 'http://localhost');
        $data = $this->requestCode($user);
        $this->withHeader('Origin', 'http://untrusted.test');
        $this->postJson('/api/reset-password/otp', $data)->assertForbidden();
        $this->withHeader('Origin', 'http://localhost');
        $this->postJson('/api/reset-password/otp', $data)->assertOk()->assertJsonMissingPath('token');
    }
}
