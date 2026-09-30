<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rbac\RbacPermission;
use App\Models\Rbac\RbacRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacPublicKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rbac_catalog_and_mutations_use_public_keys(): void
    {
        $user = User::create([
            'name' => 'Admin', 'email' => 'rbac-key@example.test',
            'password' => 'Password1!', 'role' => 'superadmin',
            'status' => User::STATUS_ACTIVE,
        ]);
        $role = RbacRole::create(['key' => 'rol_prueba', 'label' => 'Prueba', 'is_system' => false]);
        $permission = RbacPermission::create([
            'key' => 'prueba.leer', 'module' => 'Prueba', 'action' => 'Leer', 'is_system' => false,
        ]);
        $this->withToken($user->createToken('platform')->plainTextToken);

        $this->getJson('/api/rbac/catalog')->assertOk()
            ->assertJsonFragment(['key' => 'rol_prueba'])
            ->assertJsonFragment(['key' => 'prueba.leer'])
            ->assertJsonMissing(['id' => $role->id])
            ->assertJsonMissing(['id' => $permission->id]);

        $this->putJson('/api/rbac/roles/'.$role->id, ['label' => 'Erróneo'])->assertNotFound();
        $this->putJson('/api/rbac/permissions/'.$permission->id, [
            'module' => 'Prueba', 'action' => 'Erróneo',
        ])->assertNotFound();

        $this->putJson('/api/rbac/roles/'.$role->key, ['label' => 'Renombrado'])
            ->assertOk()->assertJsonPath('role.key', $role->key)
            ->assertJsonPath('role.label', 'Renombrado');
        $this->putJson('/api/rbac/permissions/'.$permission->key, [
            'module' => 'Prueba', 'action' => 'Ver',
        ])->assertOk()->assertJsonPath('permission.key', $permission->key)
            ->assertJsonPath('permission.action', 'Ver');
    }
}
