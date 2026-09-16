<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureImpersonationToken;
use App\Models\Impersonation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImpersonationSessionManager;
use App\Support\Impersonation\ImpersonationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ImpersonationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_dos_superadmins_resuelven_su_sesion_exacta_y_una_salida_no_afecta_la_otra(): void
    {
        $tenantId = 'tenant-concurrente';
        $superadminA = $this->superadmin('admin-a@plataforma.test');
        $superadminB = $this->superadmin('admin-b@plataforma.test');
        $sessionA = $this->impersonationSession($superadminA, $tenantId);
        $sessionB = $this->impersonationSession($superadminB, $tenantId);
        $this->setTenantContext($tenantId);

        $shadowA = $this->shadowWithToken($superadminA, $sessionA);
        $shadowB = $this->shadowWithToken($superadminB, $sessionB);
        $access = new ImpersonationAccess;

        $this->assertNotSame($shadowA->email, $shadowB->email);
        $this->assertSame($sessionA->session_id, $access->sessionFor($shadowA)?->session_id);
        $this->assertSame($sessionB->session_id, $access->sessionFor($shadowB)?->session_id);
        $this->assertNull((new ImpersonationAccess)->sessionFor(
            $this->shadowWithToken($superadminB, $sessionB, ['lectura']),
        ));

        // Simula la salida de A: la sesion y el gate de B siguen vigentes.
        $sessionA->update(['ended_at' => now('UTC')]);

        $this->assertNull((new ImpersonationAccess)->sessionFor($shadowA));
        $this->assertSame(
            $sessionB->session_id,
            (new ImpersonationAccess)->sessionFor($shadowB)?->session_id,
        );
        $this->assertNull($sessionB->fresh()->ended_at);
    }

    public function test_subdominio_rechaza_suplantacion_terminada_y_deja_pasar_token_normal(): void
    {
        $tenantId = 'tenant-subdominio';
        $superadmin = $this->superadmin('admin-subdominio@plataforma.test');
        $session = $this->impersonationSession($superadmin, $tenantId);
        $this->setTenantContext($tenantId);

        $shadow = $this->shadowWithToken($superadmin, $session);
        $session->update(['ended_at' => now('UTC')]);

        $request = Request::create('/api/me', 'GET');
        $request->setUserResolver(fn (): User => $shadow);
        $middleware = new EnsureImpersonationToken(new ImpersonationAccess);

        $blocked = $middleware->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'when-present',
        );

        $this->assertSame(401, $blocked->getStatusCode());

        $normalToken = new PersonalAccessToken([
            'name' => 'web',
            'abilities' => ['tenant'],
        ]);
        $normalUser = (new User)->forceFill([
            'id' => 9999,
            'name' => 'Usuario normal',
            'email' => 'normal@colegio.test',
            'status' => User::STATUS_ACTIVE,
        ])->withAccessToken($normalToken);
        $request->setUserResolver(fn (): User => $normalUser);

        $allowed = $middleware->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'when-present',
        );

        $this->assertSame(200, $allowed->getStatusCode());

        $reservedToken = new PersonalAccessToken([
            'name' => 'web',
            'abilities' => ['tenant'],
        ]);
        $reservedUser = (new User)->forceFill([
            'id' => 10000,
            'name' => 'Sombra precreada',
            'email' => 'SUPERADMIN+999@PLATAFORMA.LOCAL',
            'status' => User::STATUS_ACTIVE,
        ])->withAccessToken($reservedToken);
        $request->setUserResolver(fn (): User => $reservedUser);

        $reservedBlocked = $middleware->handle(
            $request,
            fn () => response()->json(['ok' => true]),
            'when-present',
        );

        $this->assertSame(401, $reservedBlocked->getStatusCode());
    }

    public function test_iniciar_en_otro_tenant_cierra_solo_las_sesiones_previas_del_mismo_admin(): void
    {
        $superadminA = $this->superadmin('cambio-a@plataforma.test');
        $superadminB = $this->superadmin('cambio-b@plataforma.test');
        $previousA = $this->impersonationSession($superadminA, 'tenant-a');
        $previousB = $this->impersonationSession($superadminB, 'tenant-a');
        $target = (new Tenant)->forceFill(['id' => 'tenant-b']);

        $newSession = (new ImpersonationSessionManager)->start(
            $superadminA,
            $target,
            (string) Str::uuid(),
            now('UTC')->addHour(),
            'Cambio autorizado al tenant B.',
            null,
        );

        $this->assertNotNull($previousA->fresh()->ended_at);
        $this->assertNull($previousB->fresh()->ended_at);
        $this->assertSame('tenant-b', $newSession->tenant_id);
        $this->assertNull($newSession->fresh()->ended_at);
    }

    protected function tearDown(): void
    {
        tenancy()->tenant = null;
        tenancy()->initialized = false;

        parent::tearDown();
    }

    private function superadmin(string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'Admin123!',
            'role' => 'superadmin',
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => false,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function impersonationSession(User $superadmin, string $tenantId): Impersonation
    {
        return Impersonation::create([
            'session_id' => (string) Str::uuid(),
            'superadmin_id' => $superadmin->id,
            'superadmin_email' => $superadmin->email,
            'tenant_id' => $tenantId,
            'motivo' => 'Soporte concurrente autorizado.',
            'started_at' => now('UTC'),
            'expires_at' => now('UTC')->addHour(),
        ]);
    }

    /** @param list<string> $abilities */
    private function shadowWithToken(
        User $superadmin,
        Impersonation $session,
        array $abilities = ['impersonate'],
    ): User {
        $token = new PersonalAccessToken([
            'name' => ImpersonationAccess::tokenName($session->session_id),
            'abilities' => $abilities,
        ]);

        return (new User)->forceFill([
            'id' => 1000 + $superadmin->id,
            'name' => 'Sombra '.$superadmin->id,
            'email' => User::impersonationShadowEmail($superadmin->id),
            'status' => User::STATUS_ACTIVE,
        ])->withAccessToken($token);
    }

    private function setTenantContext(string $tenantId): void
    {
        $tenant = new Tenant;
        $tenant->forceFill(['id' => $tenantId]);
        tenancy()->tenant = $tenant;
        tenancy()->initialized = true;
    }
}
