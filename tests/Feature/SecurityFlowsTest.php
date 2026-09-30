<?php

namespace Tests\Feature;

use App\Jobs\PersistAuditLog;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SecurityFlowsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@test.test', 'password' => 'OriginalPassword123', 'role' => 'superadmin', 'status' => 'active']);
    }

    public function test_reset_link_is_single_use_revokes_sessions_and_does_not_expose_tokens(): void
    {
        Notification::fake();
        $user = $this->admin();
        $user->createToken('old');
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $this->assertNotSame($token, DB::table('password_reset_tokens')->value('token'));
        $weak = ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345'];
        $this->postJson('/api/reset-password', $weak)->assertUnprocessable()->assertJsonValidationErrors('password');
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword!12345', 'password_confirmation' => 'NewPassword!12345'];
        $this->postJson('/api/reset-password', $data)->assertOk();
        $this->assertTrue(Hash::check('NewPassword!12345', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/reset-password', $data)->assertUnprocessable();
        $this->assertDatabaseHas('platform_audit_logs', ['accion' => 'PASSWORD_RESET']);
        $this->assertStringNotContainsString($token, PlatformAuditLog::all()->toJson());
    }

    public function test_unknown_email_has_same_public_response(): void
    {
        Notification::fake();
        $user = $this->admin();
        $known = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'unknown@test.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
    }

    public function test_only_platform_superadmin_can_query_audit_and_read_is_itself_audited(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->withToken($admin->createToken('platform')->plainTextToken)->getJson('/api/platform/auditoria')->assertOk()->assertJsonStructure(['data', 'total']);
        $this->assertDatabaseHas('platform_audit_logs', ['accion' => 'READ', 'recurso' => 'auditoria']);
        $this->getJson('/api/platform/auditoria?hasta=2026-12-31')->assertOk();
        $admin->update(['role' => 'rector']);
        $this->app['auth']->forgetGuards();
        $this->withToken($admin->createToken('platform')->plainTextToken)->getJson('/api/platform/auditoria')->assertForbidden();
    }

    public function test_logout_and_failed_login_are_audited(): void
    {
        $user = $this->admin();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->assertDatabaseHas('platform_audit_logs', ['accion' => 'LOGIN_FAILED']);
        $this->withToken($user->createToken('platform')->plainTextToken)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseHas('platform_audit_logs', ['accion' => 'LOGOUT']);
    }

    public function test_fallback_retains_snapshots_and_redacts_credentials(): void
    {
        Bus::fake();
        Schema::drop('platform_audit_logs');
        AuditLogger::platform(null, 'UPDATE', 'perfil', '1', ['nombre' => 'Antes'], ['nombre' => 'Después', 'nested' => ['password' => 'sensitive']], 'Prueba', 'colegio');
        Bus::assertDispatched(PersistAuditLog::class, fn ($job) => $job->payload['attributes']['valor_previo'] === ['nombre' => 'Antes']
            && $job->payload['attributes']['valor_nuevo']['nested']['password'] === '[REDACTED]'
            && $job->payload['attributes']['motivo'] === 'Prueba'
            && $job->payload['attributes']['tenant_id'] === 'colegio');
    }

    public function test_audit_models_reject_mutation(): void
    {
        AuditLogger::platform(null, 'CREATE', 'plan');
        $this->expectException(\LogicException::class);
        PlatformAuditLog::first()->update(['accion' => 'HIDDEN']);
    }
}
