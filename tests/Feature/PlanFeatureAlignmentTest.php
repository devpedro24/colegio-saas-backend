<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Rbac\RbacPermission;
use App\Models\User;
use App\Plans\PlanCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanFeatureAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_catalog_permissions_have_a_valid_plan_feature_and_no_guardian_permission(): void
    {
        $this->seed(DatabaseSeeder::class);

        $keys = PlanCatalog::featureKeys();
        $this->assertContains('nucleo', $keys);
        $this->assertNotContains('app_acudientes', $keys);
        foreach (RbacPermission::all() as $permission) {
            $this->assertContains($permission->feature_key, $keys, $permission->key);
        }
        $this->assertSame('academico', RbacPermission::where('key', 'academico.anos.gestionar')->value('feature_key'));
        $this->assertSame('academico', RbacPermission::where('key', 'boletines.emisiones.aprobar')->value('feature_key'));
        $this->assertSame('nucleo', RbacPermission::where('key', 'config.correo')->value('feature_key'));
        $this->assertSame('nucleo', RbacPermission::where('key', 'portal.acceder')->value('feature_key'));
        $this->assertFalse(RbacPermission::whereIn('key', ['mensajes.acudientes', 'convivencia.citar_acudientes'])->exists());
        foreach (Plan::all() as $plan) {
            $this->assertContains('nucleo', $plan->features);
            $this->assertNotContains('app_acudientes', $plan->features);
        }
    }

    public function test_plan_api_keeps_core_feature_and_rejects_removed_guardian_app(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'plan-feature@example.test',
            'password' => 'Password1!', 'role' => 'superadmin',
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->withToken($admin->createToken('platform')->plainTextToken);

        $this->putJson('/api/plans/estandar', [
            'key' => 'estandar', 'name' => 'Estándar', 'features' => ['academico'],
        ])->assertOk()->assertJsonPath('plan.features.0', 'nucleo');
        $this->putJson('/api/plans/estandar', [
            'key' => 'estandar', 'name' => 'Estándar', 'features' => ['app_acudientes'],
        ])->assertUnprocessable()->assertJsonValidationErrors('features.0');
        $this->postJson('/api/rbac/permissions', [
            'key' => 'prueba.sin_plan', 'module' => 'Prueba', 'action' => 'Sin plan',
        ])->assertUnprocessable()->assertJsonValidationErrors('feature_key');
        $this->postJson('/api/rbac/permissions', [
            'key' => 'mensajes.acudientes', 'module' => 'Comunicaciones',
            'action' => 'Portal de acudientes', 'feature_key' => 'comunicacion_basica',
        ])->assertUnprocessable()->assertJsonValidationErrors('key');
    }
}
