<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\Sede;
use App\Models\CustomRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SchoolPlanSedeGuard;
use App\Services\SedeProvisioner;
use App\Services\TenantPlanAccess;
use App\Services\TenantProvisioner;
use App\Support\OpaqueUrlToken;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantRolePlanTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    public function test_standard_has_two_additional_roles_and_downgrade_preserves_but_disables_them(): void
    {
        [$school, $rectorToken, $teacherToken, $teacherSelector] = $this->school('estandar');
        $api = 'http://'.$school->slug.'.localhost/api';

        $this->withToken($rectorToken)->getJson($api.'/rbac/roles?per_page=1000')
            ->assertOk()->assertJsonPath('custom_role_limit', 2)->assertJsonPath('custom_role_count', 0);
        $first = $this->withToken($rectorToken)->postJson($api.'/rbac/roles', ['label' => 'Apoyo académico'])
            ->assertCreated()->json('role.key');
        $this->assertStringStartsWith('custom_', $first);
        $this->withToken($rectorToken)->postJson($api.'/rbac/roles', ['label' => 'Convivencia'])
            ->assertCreated();
        $this->withToken($rectorToken)->postJson($api.'/rbac/roles', ['label' => 'Tercer rol'])
            ->assertUnprocessable();
        $this->withToken($rectorToken)->putJson($api.'/rbac/roles/'.$first.'/permissions/indicadores.grupo.editar',
            ['granted' => true])->assertOk();
        $this->withToken($rectorToken)->putJson($api.'/rbac/usuarios/'.$teacherSelector.'/roles-adicionales',
            ['roles' => [$first]])->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($teacherToken)->getJson($api.'/me')->assertOk()
            ->assertJsonPath('user.roles.1', $first);
        $school->run(function () {
            $teacher = User::where('email', 'docente@roles.test')->firstOrFail();
            $this->assertTrue($teacher->can('indicadores.grupo.editar'));
        });

        $school->update(['plan' => 'esencial']);
        app('auth')->forgetGuards();
        $permissions = $this->withToken($teacherToken)->getJson($api.'/me')->assertOk()->json('user.permissions');
        $this->assertNotContains('indicadores.grupo.editar', $permissions);
        app('auth')->forgetGuards();
        $this->withToken($rectorToken)->getJson($api.'/rbac/roles?per_page=1000')
            ->assertOk()->assertJsonPath('custom_role_limit', 0)
            ->assertJsonPath('custom_role_count', 2);
        $school->run(function () use ($first) {
            $this->assertSame(2, CustomRole::count());
            $this->assertTrue(User::where('email', 'docente@roles.test')->firstOrFail()->hasRole($first));
        });
    }

    public function test_basic_blocks_role_creation_and_plan_feature_even_for_rector(): void
    {
        [$school, $rectorToken] = $this->school('esencial');
        $api = 'http://'.$school->slug.'.localhost/api';
        $this->withToken($rectorToken)->postJson($api.'/rbac/roles', ['label' => 'Sin cupo'])
            ->assertUnprocessable();
        $this->withToken($rectorToken)->putJson($api.'/rbac/roles/rector/permissions/aula.ver_todas',
            ['granted' => true])->assertUnprocessable();
        $this->withToken($rectorToken)->getJson($api.'/me')->assertOk()
            ->assertJsonMissing(['aula.ver_todas']);
    }

    public function test_premium_allows_multiple_roles_and_non_rector_cannot_manage_them(): void
    {
        [$school, $rectorToken, $teacherToken] = $this->school('premium');
        $api = 'http://'.$school->slug.'.localhost/api';
        app('auth')->forgetGuards();
        $this->withToken($teacherToken)->postJson($api.'/rbac/roles', ['label' => 'Prohibido'])->assertForbidden();
        app('auth')->forgetGuards();
        for ($index = 1; $index <= 3; $index++) {
            $this->withToken($rectorToken)->postJson($api.'/rbac/roles', ['label' => 'Rol '.$index])
                ->assertCreated();
        }
        $this->withToken($rectorToken)->getJson($api.'/rbac/roles?per_page=1000')
            ->assertOk()->assertJsonPath('custom_role_count', 3);
    }

    public function test_plan_downgrade_blocks_aula_api_and_reupgrade_restores_prior_grants(): void
    {
        [$school, , $teacherToken] = $this->school('estandar');
        $api = 'http://'.$school->slug.'.localhost/api';
        $school->run(function (): void {
            $this->assertTrue(User::where('email', 'docente@roles.test')->firstOrFail()->can('aula.ver_asignadas'));
        });

        $school->update(['plan' => 'esencial']);
        $school->run(fn () => (new RbacSeeder)->run());
        app('auth')->forgetGuards();
        $this->withToken($teacherToken)->getJson($api.'/aula/catalogo')->assertForbidden();
        app('auth')->forgetGuards();
        $this->assertNotContains('aula.ver_asignadas',
            $this->withToken($teacherToken)->getJson($api.'/me')->assertOk()->json('user.permissions'));

        $school->update(['plan' => 'estandar']);
        $school->run(fn () => (new RbacSeeder)->run());
        app('auth')->forgetGuards();
        $this->assertContains('aula.ver_asignadas',
            $this->withToken($teacherToken)->getJson($api.'/me')->assertOk()->json('user.permissions'));
    }

    public function test_editing_plan_features_blocks_existing_permissions_without_tenant_reseed(): void
    {
        [$school, , $teacherToken] = $this->school('estandar');
        $api = 'http://'.$school->slug.'.localhost/api';
        $plan = Plan::where('key', 'estandar')->firstOrFail();
        $plan->update(['features' => array_values(array_diff($plan->features, ['aula']))]);

        app('auth')->forgetGuards();
        $this->withToken($teacherToken)->getJson($api.'/aula/catalogo')->assertForbidden();
        app('auth')->forgetGuards();
        $this->assertNotContains('aula.ver_asignadas',
            $this->withToken($teacherToken)->getJson($api.'/me')->assertOk()->json('user.permissions'));
    }

    public function test_removing_academic_feature_blocks_academic_permissions_but_keeps_core_access(): void
    {
        [$school, $rectorToken] = $this->school('esencial');
        $plan = Plan::where('key', 'esencial')->firstOrFail();
        $plan->update(['features' => array_values(array_diff($plan->features, ['academico']))]);

        app('auth')->forgetGuards();
        $permissions = $this->withToken($rectorToken)
            ->getJson('http://'.$school->slug.'.localhost/api/me')
            ->assertOk()->json('user.permissions');
        $this->assertNotContains('academico.anos.gestionar', $permissions);
        $this->assertNotContains('boletines.emisiones.aprobar', $permissions);
        $this->assertContains('config.identidad', $permissions);

        $school->run(function (): void {
            $rector = User::where('email', 'rector@roles.test')->firstOrFail();
            $this->assertFalse($rector->can('academico.anos.gestionar'));
            $this->assertTrue($rector->can('config.identidad'));
            // Una concesión histórica fuera del catálogo no reabre un permiso retirado.
            $rector->givePermissionTo(Permission::findOrCreate('mensajes.acudientes', 'web'));
            $this->assertFalse($rector->can('mensajes.acudientes'));
            $this->assertNotContains('mensajes.acudientes', app(TenantPlanAccess::class)->effectivePermissions($rector));
        });
    }

    public function test_additional_role_is_inherited_by_sede_and_can_be_assigned_without_changing_primary_role(): void
    {
        [$school, $rectorToken] = $this->school('estandar');
        [$child, , $childTeacherToken, $childTeacherSelector] = $this->school('estandar');
        $child->update(['parent_id' => $school->id, 'tipo' => Tenant::TIPO_SEDE]);
        $sedeToken = $school->run(function () use ($child): string {
            $sede = Sede::create(['nombre' => 'Sede Norte', 'tenant_id' => $child->id,
                'estado' => Sede::ESTADO_ACTIVA]);

            return OpaqueUrlToken::for('sede', $sede->id);
        });
        $api = 'http://'.$school->slug.'.localhost/api';
        $role = $this->withToken($rectorToken)->postJson($api.'/rbac/roles', ['label' => 'Apoyo sede'])
            ->assertCreated()->json('role.key');
        $this->withToken($rectorToken)->putJson($api.'/rbac/roles/'.$role.'/permissions/indicadores.grupo.editar',
            ['granted' => true])->assertOk();
        $this->withToken($rectorToken)->putJson($api.'/rbac/usuarios/'.$childTeacherSelector.'/roles-adicionales',
            ['roles' => [$role], 'sede_url_token' => $sedeToken])->assertOk();

        $child->run(function () use ($role): void {
            $teacher = User::where('email', 'docente@roles.test')->firstOrFail();
            $this->assertSame('docente', $teacher->role);
            $this->assertTrue($teacher->hasRole($role));
            $this->assertTrue(Role::findByName($role, 'web')
                ->hasPermissionTo('indicadores.grupo.editar'));
            $this->assertNotNull(tenant()->parent_id);
            $this->assertSame('estandar', Tenant::on(config('tenancy.database.central_connection'))
                ->find(tenant()->parent_id)?->plan);
            $this->assertSame(2, app(TenantPlanAccess::class)->customRoleLimit());
            $this->assertContains($role, app(TenantPlanAccess::class)->effectiveCustomRoleKeys());
            $this->assertContains('indicadores.grupo.editar',
                app(TenantPlanAccess::class)->effectivePermissions($teacher));
            $this->assertTrue($teacher->can('indicadores.grupo.editar'));
            $this->assertSame('Apoyo sede', CustomRole::whereKey($role)->value('label'));
        });
        app('auth')->forgetGuards();
        $this->assertContains('indicadores.grupo.editar',
            $this->withToken($childTeacherToken)->getJson('http://'.$child->slug.'.localhost/api/me')
                ->assertOk()->json('user.permissions'));

        app('auth')->forgetGuards();
        $this->withToken($rectorToken)->deleteJson($api.'/rbac/roles/'.$role)->assertUnprocessable();
        $this->withToken($rectorToken)->putJson($api.'/rbac/usuarios/'.$childTeacherSelector.'/roles-adicionales',
            ['roles' => [], 'sede_url_token' => $sedeToken])->assertOk();
        $this->withToken($rectorToken)->deleteJson($api.'/rbac/roles/'.$role)->assertOk();
        $child->run(fn () => $this->assertFalse(CustomRole::whereKey($role)->exists()));
    }

    public function test_plan_downgrade_cannot_orphan_an_active_sede(): void
    {
        [$school] = $this->school('estandar');
        [$child] = $this->school('estandar');
        $child->update(['parent_id' => $school->id, 'tipo' => Tenant::TIPO_SEDE]);

        $this->expectException(ValidationException::class);
        SchoolPlanSedeGuard::ensureSchoolFits($school, Plan::where('key', 'esencial')->firstOrFail());
    }

    public function test_new_sede_inherits_roles_already_created_by_school(): void
    {
        [$school, $rectorToken] = $this->school('estandar');
        $api = 'http://'.$school->slug.'.localhost/api';
        $role = $this->withToken($rectorToken)->postJson($api.'/rbac/roles', ['label' => 'Equipo pedagógico'])
            ->assertCreated()->json('role.key');
        $this->withToken($rectorToken)->putJson($api.'/rbac/roles/'.$role.'/permissions/indicadores.grupo.editar',
            ['granted' => true])->assertOk();

        ['tenant' => $child] = app(SedeProvisioner::class)->provision($school, [
            'slug' => 'norte', 'name' => 'Sede Norte', 'coordinador_email' => 'coord@roles.test',
            'heredar' => false,
        ]);
        $child->run(function () use ($role): void {
            $this->assertSame('Equipo pedagógico', CustomRole::whereKey($role)->value('label'));
            $this->assertTrue(Role::findByName($role, 'web')
                ->hasPermissionTo('indicadores.grupo.editar'));
        });
    }

    private function school(string $plan): array
    {
        $this->seed(DatabaseSeeder::class);
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio roles', 'slug' => 'roles-'.uniqid(),
            'rector_email' => 'rector@roles.test', 'plan' => $plan,
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        [$rectorToken, $teacherToken, $teacherSelector] = $school->run(function () {
            $rector = User::where('email', 'rector@roles.test')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $teacher = User::create(['name' => 'Docente', 'email' => 'docente@roles.test',
                'password' => 'Password!123', 'role' => 'docente', 'status' => User::STATUS_ACTIVE]);
            $teacher->assignRole('docente');

            return [$rector->createToken('web')->plainTextToken, $teacher->createToken('web')->plainTextToken,
                OpaqueUrlToken::for('usuario', $teacher->id)];
        });

        return [$school, $rectorToken, $teacherToken, $teacherSelector];
    }
}
