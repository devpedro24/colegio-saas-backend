<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ImpersonationSessionManager;
use App\Services\SedeProvisioner;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImpersonationShadowSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_shadow_precreado_pierde_password_tokens_y_no_es_administrable(): void
    {
        $slug = 'shadow-'.Str::lower(Str::random(8));
        $superadmin = User::create([
            'name' => 'Superadmin',
            'email' => 'admin-shadow@plataforma.test',
            'password' => 'Admin123!',
            'role' => 'superadmin',
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => false,
            'two_factor_confirmed_at' => now(),
        ]);
        $tenant = Tenant::create([
            'name' => 'Colegio shadow',
            'slug' => $slug,
            'plan' => Tenant::PLAN_ESENCIAL,
            'status' => Tenant::STATUS_ACTIVE,
            'tipo' => Tenant::TIPO_COLEGIO,
        ]);
        $tenant->domains()->create(['domain' => $slug]);

        try {
            [$shadowId, $rectorToken] = $tenant->run(function () use ($superadmin): array {
                $permission = Permission::findOrCreate('usuarios.gestionar', 'web');
                $role = Role::findOrCreate('rector', 'web');
                $role->givePermissionTo($permission);

                $shadow = User::create([
                    'name' => 'Cuenta atacante',
                    'email' => strtoupper(User::impersonationShadowEmail($superadmin->id)),
                    'password' => 'KnownPass123!',
                    'role' => 'rector',
                    'status' => User::STATUS_ACTIVE,
                    'must_change_password' => false,
                ]);
                $shadow->assignRole($role);
                $shadow->createToken('web-malicious', ['tenant']);

                $rector = User::create([
                    'name' => 'Rector real',
                    'email' => 'rector-real@colegio.test',
                    'password' => 'Rector123!',
                    'role' => 'rector',
                    'status' => User::STATUS_ACTIVE,
                    'must_change_password' => false,
                    'two_factor_confirmed_at' => now(),
                ]);
                $rector->assignRole($role);

                return [$shadow->id, $rector->createToken('web', ['tenant'])->plainTextToken];
            });

            $platformToken = $superadmin->createToken('platform', ['platform'])->plainTextToken;
            $response = $this->withToken($platformToken)->postJson('/api/platform/impersonar', [
                'colegio_id' => (string) $tenant->id,
                'motivo' => 'Prueba autorizada de hardening del shadow.',
            ])->assertOk();

            $sessionId = $response->json('data.session_id');
            $tenant->run(function () use ($superadmin, $sessionId): void {
                $shadow = User::withTrashed()
                    ->whereRaw('LOWER(email) = ?', [strtolower(User::impersonationShadowEmail($superadmin->id))])
                    ->sole();

                $this->assertSame(User::impersonationShadowEmail($superadmin->id), $shadow->email);
                $this->assertFalse(Hash::check('KnownPass123!', $shadow->password));
                $this->assertSame(0, $shadow->tokens()->where('name', 'web-malicious')->count());
                $this->assertSame(1, $shadow->tokens()->count());
                $this->assertTrue($shadow->tokens()->where('name', 'impersonation:'.$sessionId)->exists());
            });

            // El RequestGuard de Sanctum conserva el usuario resuelto entre
            // solicitudes dentro del mismo proceso de prueba. Obligamos a que
            // el siguiente request autentique el token contra la BD tenant.
            $this->app['auth']->forgetGuards();

            $host = $slug.'.'.config('tenancy.tenant_base_domain', 'localhost');
            $list = $this->withToken($rectorToken)
                ->getJson('http://'.$host.'/api/usuarios')
                ->assertOk();
            $listedEmails = collect($list->json('data'))->pluck('email')->map('strtolower');
            $this->assertNotContains(strtolower(User::impersonationShadowEmail($superadmin->id)), $listedEmails);

            $this->withToken($rectorToken)
                ->putJson('http://'.$host.'/api/usuarios/'.$shadowId, ['name' => 'Intento de edicion'])
                ->assertNotFound();

            $this->withToken($rectorToken)
                ->postJson('http://'.$host.'/api/usuarios', [
                    'name' => 'Intento reservado',
                    'email' => 'SUPERADMIN+OTRO@PLATAFORMA.LOCAL',
                    'role' => 'rector',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('email');
        } finally {
            tenancy()->end();
            app(ImpersonationSessionManager::class)->endAll($superadmin);
            $tenant->domains()->delete();
            $tenant->delete();
        }
    }

    public function test_provisioners_rechazan_namespace_shadow_case_insensitive(): void
    {
        try {
            (new TenantProvisioner)->provision([
                'name' => 'No crear',
                'slug' => 'no-crear',
                'rector_email' => 'SUPERADMIN+10@PLATAFORMA.LOCAL',
            ]);
            $this->fail('TenantProvisioner acepto un correo shadow reservado.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('reservado', $exception->getMessage());
        }

        $colegio = (new Tenant)->forceFill([
            'id' => 'colegio-test',
            'tipo' => Tenant::TIPO_COLEGIO,
        ]);

        try {
            (new SedeProvisioner)->provision($colegio, [
                'slug' => 'norte',
                'name' => 'Norte',
                'coordinador_email' => 'SuperAdmin+Legacy@Plataforma.Local',
                'heredar' => false,
            ]);
            $this->fail('SedeProvisioner acepto un correo shadow reservado.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('reservado', $exception->getMessage());
        }
    }
}
