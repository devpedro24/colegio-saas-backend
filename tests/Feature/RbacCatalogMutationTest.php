<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PlatformDataChanged;
use App\Models\PlatformAuditLog;
use App\Models\Rbac\RbacPermission;
use App\Models\User;
use App\Services\TenantRbacSynchronizationException;
use App\Services\TenantRbacSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacCatalogMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_todas_las_mutaciones_se_sincronizan_y_auditan(): void
    {
        Event::fake([PlatformDataChanged::class]);
        $this->authenticateSuperadmin();

        $permissionId = $this->postJson('/api/rbac/permissions', [
            'key' => 'custom.report',
            'module' => 'Personalizado',
            'action' => 'Consultar reporte',
            'description' => 'Permiso creado por el superadministrador.',
        ])->assertCreated()
            ->assertJsonPath('permission.key', 'custom.report')
            ->assertJsonPath('sync.attempted', 0)
            ->assertJsonPath('sync.policy', TenantRbacSynchronizer::OBSOLETE_POLICY)
            ->json('permission.id');

        $this->putJson('/api/rbac/permissions/'.$permissionId, [
            'module' => 'Personalizado',
            'action' => 'Exportar reporte',
            'description' => null,
        ])->assertOk()
            ->assertJsonPath('permission.action', 'Exportar reporte')
            ->assertJsonPath('sync.failed', []);

        $roleId = $this->postJson('/api/rbac/roles', [
            'key' => 'custom_role',
            'label' => 'Rol personalizado',
        ])->assertCreated()
            ->assertJsonPath('role.key', 'custom_role')
            ->assertJsonPath('sync.attempted', 0)
            ->json('role.id');

        $this->putJson('/api/rbac/roles/'.$roleId, [
            'label' => 'Rol personalizado actualizado',
        ])->assertOk()
            ->assertJsonPath('role.label', 'Rol personalizado actualizado');

        $matrix = [
            'role_key' => 'custom_role',
            'permission_key' => 'custom.report',
            'type' => 'structural',
            'level' => 'ver',
        ];
        $this->putJson('/api/rbac/matrix', $matrix)->assertOk()
            ->assertJsonPath('type', 'structural')
            ->assertJsonPath('level', 'ver')
            ->assertJsonPath('sync.attempted', 0);

        $this->putJson('/api/rbac/matrix', array_merge($matrix, [
            'type' => 'denied',
            'level' => null,
        ]))->assertOk()
            ->assertJsonPath('type', 'denied')
            ->assertJsonPath('sync.failed', []);

        $this->deleteJson('/api/rbac/permissions/'.$permissionId)->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('obsolete_policy', TenantRbacSynchronizer::OBSOLETE_POLICY);
        $this->deleteJson('/api/rbac/roles/'.$roleId)->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('sync.attempted', 0);

        $this->assertDatabaseMissing('rbac_permissions', ['key' => 'custom.report']);
        $this->assertDatabaseMissing('rbac_roles', ['key' => 'custom_role']);
        $this->assertDatabaseMissing('rbac_matrix', [
            'role_key' => 'custom_role',
            'permission_key' => 'custom.report',
        ]);
        $this->assertSame(2, PlatformAuditLog::where('recurso', 'rbac.permission')->where('accion', 'CREATE')->count()
            + PlatformAuditLog::where('recurso', 'rbac.permission')->where('accion', 'DELETE')->count());
        $this->assertDatabaseHas('platform_audit_logs', ['accion' => 'UPDATE', 'recurso' => 'rbac.permission']);
        $this->assertDatabaseHas('platform_audit_logs', ['accion' => 'CREATE', 'recurso' => 'rbac.role']);
        $this->assertDatabaseHas('platform_audit_logs', ['accion' => 'UPDATE', 'recurso' => 'rbac.role']);
        $this->assertDatabaseHas('platform_audit_logs', ['accion' => 'DELETE', 'recurso' => 'rbac.role']);
        $this->assertSame(2, PlatformAuditLog::where('recurso', 'rbac.matrix')->where('accion', 'UPDATE')->count());
        Event::assertDispatchedTimes(PlatformDataChanged::class, 8);
    }

    public function test_fallo_de_propagacion_revierte_catalogo_y_reporta_compensacion(): void
    {
        Event::fake([PlatformDataChanged::class]);
        $this->authenticateSuperadmin();

        $failed = [
            'attempted' => 2,
            'synced' => ['tenant-ok'],
            'failed' => ['tenant-fail' => 'conexion rechazada'],
            'policy' => TenantRbacSynchronizer::OBSOLETE_POLICY,
        ];
        $compensation = [
            'attempted' => 2,
            'synced' => ['tenant-ok', 'tenant-fail'],
            'failed' => [],
            'policy' => TenantRbacSynchronizer::OBSOLETE_POLICY,
        ];
        $fake = new class($failed, $compensation) extends TenantRbacSynchronizer
        {
            public int $syncCalls = 0;

            public int $compensationCalls = 0;

            public function __construct(
                private readonly array $failedResult,
                private readonly array $compensationResult,
            ) {}

            public function syncAll(?string $planKey = null): array
            {
                $this->syncCalls++;

                throw new TenantRbacSynchronizationException($this->failedResult);
            }

            public function syncAllWithResult(?string $planKey = null): array
            {
                $this->compensationCalls++;

                return $this->compensationResult;
            }
        };
        $this->app->instance(TenantRbacSynchronizer::class, $fake);

        $this->postJson('/api/rbac/permissions', [
            'key' => 'custom.rollback',
            'module' => 'Personalizado',
            'action' => 'Debe revertirse',
        ])->assertStatus(503)
            ->assertJsonPath('sync.failed.tenant-fail', 'conexion rechazada')
            ->assertJsonPath('compensation.failed', [])
            ->assertJsonPath('compensation.synced.1', 'tenant-fail');

        $this->assertSame(1, $fake->syncCalls);
        $this->assertSame(1, $fake->compensationCalls);
        $this->assertFalse(RbacPermission::where('key', 'custom.rollback')->exists());
        $audit = PlatformAuditLog::where('accion', 'RBAC_SYNC_FAILED')->firstOrFail();
        $this->assertSame('rbac.permission', $audit->recurso);
        $this->assertSame($failed, $audit->valor_nuevo['sync']);
        $this->assertSame($compensation, $audit->valor_nuevo['compensation']);
        Event::assertNotDispatched(PlatformDataChanged::class);
    }

    private function authenticateSuperadmin(): User
    {
        $user = User::create([
            'name' => 'Super Admin RBAC',
            'email' => 'rbac-admin-'.uniqid().'@plataforma.test',
            'password' => 'Admin123!',
            'role' => 'superadmin',
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => false,
            'two_factor_confirmed_at' => now(),
        ]);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
