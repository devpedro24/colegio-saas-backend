<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\OpaqueUrlToken;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\DatabaseConfig;
use Tests\TestCase;

final class UserInputValidationTest extends TestCase
{
    private Tenant $school;

    private string $base = 'http://usuarios.localhost/api/usuarios';

    protected function setUp(): void
    {
        parent::setUp();
        $database = config('database.connections.pgsql.database');
        if (config('database.default') !== 'pgsql' || ! preg_match('/^codex_sedes_test_[a-f0-9]{12}$/D', $database)) {
            throw new \RuntimeException('Use scripts/test-sedes-postgres.php --users.');
        }
        $this->artisan('migrate:fresh', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
        DatabaseConfig::generateDatabaseNamesUsing(fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->id), 0, 10));
        $this->seed(DatabaseSeeder::class);
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->school = $this->school('usuarios');
        $token = $this->school->run(function () {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);

            return $rector->createToken('test')->plainTextToken;
        });
        $this->withToken($token);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        DB::setDefaultConnection(config('tenancy.database.central_connection'));
        parent::tearDown();
    }

    private function school(string $slug): Tenant
    {
        $school = app(TenantProvisioner::class)->provision(['name' => 'Colegio de pruebas', 'slug' => $slug,
            'plan' => 'premium', 'rector_email' => 'rector@'.$slug.'.test'])['tenant'];
        $school->update(['status' => 'active']);

        return $school;
    }

    public function test_create_and_edit_validate_every_managed_role_without_partial_updates(): void
    {
        foreach (['estudiante', 'docente', 'coord_academico', 'coord_convivencia', 'coord_combinado'] as $role) {
            $input = ['name' => "María José O'Connor", 'email' => $role.'@example.test', 'role' => $role];
            $before = $this->school->run(fn () => User::count());
            foreach (['Ana123', '123', 'Ana_Pérez', '<script>Ana</script>', '', ['Ana']] as $invalid) {
                $this->postJson($this->base, [...$input, 'name' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('name');
            }
            foreach (['sin-arroba', 'ana@', str_repeat('a', 256).'@example.test', ['a@example.test']] as $invalid) {
                $this->postJson($this->base, [...$input, 'email' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('email');
            }
            $this->school->run(fn () => $this->assertSame($before, User::count()));
            $response = $this->postJson($this->base, $input)->assertCreated();
            $token = $response->json('data.url_token');
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $token);
            $this->assertSame($token, $response->json('data.id'));
            $response->assertJsonPath('data.name', $input['name']);
            foreach (['Ana7', '', null, ['Ana']] as $invalid) {
                $this->putJson($this->base.'/'.$token, ['name' => $invalid, 'status' => 'inactive'])
                    ->assertUnprocessable()->assertJsonValidationErrors('name');
            }
            $this->putJson($this->base.'/'.$token, ['name' => 'Cambio rechazado', 'email' => 'no-es-correo', 'status' => 'inactive'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->school->run(function () use ($input): void {
                $user = User::where('email', $input['email'])->firstOrFail();
                $this->assertSame($input['name'], $user->name);
                $this->assertSame('active', $user->status);
            });
            $this->putJson($this->base.'/'.$token, ['name' => 'Óscar Jean-Luc Muñoz'])->assertOk()->assertJsonPath('data.name', 'Óscar Jean-Luc Muñoz');
            $this->putJson($this->base.'/'.$token, ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        }
    }

    public function test_validation_applies_in_child_sedes_and_tokens_stay_scoped(): void
    {
        $child = $this->school('sede-usuarios');
        $child->update(['parent_id' => $this->school->id, 'tipo' => Tenant::TIPO_SEDE]);
        $sedeToken = $this->school->run(function () use ($child) {
            $sede = Sede::create(['nombre' => 'Sede Norte', 'tenant_id' => $child->id, 'estado' => Sede::ESTADO_ACTIVA]);

            return OpaqueUrlToken::for('sede', $sede->id);
        });
        $input = ['name' => 'Ana Pérez', 'email' => 'coordinacion@example.test', 'role' => 'coord_combinado', 'sede_url_token' => $sedeToken];
        $this->postJson($this->base, [...$input, 'name' => 'Ana9'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $token = $this->postJson($this->base, $input)->assertCreated()->json('data.url_token');
        $this->putJson($this->base.'/'.$token, ['sede_url_token' => $sedeToken, 'name' => 'Ana8'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson($this->base.'/'.$token, ['sede_url_token' => $sedeToken, 'name' => 'Lucía Pérez'])
            ->assertOk()->assertJsonPath('data.name', 'Lucía Pérez');
        $this->putJson($this->base.'/'.$token, ['name' => 'No autorizado'])->assertNotFound();
        $child->run(fn () => $this->assertSame('Lucía Pérez', User::where('email', $input['email'])->firstOrFail()->name));
        $this->school->run(fn () => $this->assertFalse(User::where('email', $input['email'])->exists()));
    }

    public function test_account_validation_covers_school_roles_and_preserves_managed_student_identity(): void
    {
        $api = 'http://usuarios.localhost/api';
        foreach (['rector', 'estudiante', 'docente', 'coord_academico', 'coord_convivencia', 'coord_combinado', 'secretaria'] as $role) {
            [$id, $token] = $this->school->run(function () use ($role) {
                $user = User::create(['name' => 'Ana María', 'email' => $role.'@cuentas.test', 'password' => 'Cuenta-123!',
                    'role' => $role, 'status' => 'active', 'must_change_password' => false, 'phone' => '+573001234567']);
                $user->assignRole($role);

                return [$user->id, $user->createToken('account')->plainTextToken];
            });
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson($api.'/account')->assertOk()
                ->assertJsonPath('user.profile_capabilities.edit_name', $role !== 'estudiante');
            foreach (['Ana1', '<script>Ana</script>', ['Ana']] as $invalid) {
                $this->putJson($api.'/account/profile', ['name' => $invalid, 'phone' => '+12015550123'])
                    ->assertUnprocessable()->assertJsonValidationErrors('name');
            }
            $this->putJson($api.'/account/profile', ['name' => 'Ana María', 'phone' => '+5730012345678'])
                ->assertUnprocessable()->assertJsonValidationErrors('phone');
            $this->postJson($api.'/account/email', ['email' => 'no-es-correo', 'password' => 'Cuenta-123!'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->school->run(function () use ($id, $role): void {
                $user = User::findOrFail($id);
                $this->assertSame('Ana María', $user->name);
                $this->assertSame('+573001234567', $user->phone);
                $this->assertSame($role.'@cuentas.test', $user->email);
            });
            if ($role === 'estudiante') {
                $this->putJson($api.'/account/profile', ['name' => 'Otra persona', 'phone' => '+12015550123'])->assertForbidden();
                $this->postJson($api.'/account/email', ['email' => 'otro@example.test', 'password' => 'Cuenta-123!'])->assertForbidden();
            }
            $name = $role === 'estudiante' ? 'Ana María' : "María-José O'Connor";
            $this->putJson($api.'/account/profile', ['name' => $name, 'phone' => '+12015550123'])
                ->assertOk()->assertJsonPath('user.name', $name)->assertJsonPath('user.phone', '+12015550123');
            if ($role !== 'estudiante') {
                $this->postJson($api.'/account/email', ['email' => 'nuevo-'.$role.'@cuentas.test', 'password' => 'Cuenta-123!'])
                    ->assertOk()->assertJsonPath('user.email', 'nuevo-'.$role.'@cuentas.test');
            }
            $this->getJson($api.'/account')->assertOk()->assertJsonPath('user.name', $name);
            $this->getJson($api.'/me')->assertOk()->assertJsonPath('user.name', $name);
        }
    }
}
