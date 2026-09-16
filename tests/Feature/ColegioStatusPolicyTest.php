<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ColegioStatusPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('security.mfa.required_for_platform', false);
        Sanctum::actingAs(User::factory()->create([
            'role' => 'superadmin',
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => false,
        ]), ['platform']);
    }

    public function test_no_permite_activar_manualmente_un_colegio_en_configuracion(): void
    {
        $this->insertTenant('parent001', Tenant::TIPO_COLEGIO, null, Tenant::STATUS_CONFIGURING);

        $this->withHeader('Host', 'localhost')
            ->patchJson('/api/colegios/parent001/status', ['status' => Tenant::STATUS_ACTIVE])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Solo se puede suspender un colegio activo o reactivar uno suspendido. La activacion inicial depende de completar la configuracion minima.');

        $this->assertDatabaseHas('tenants', [
            'id' => 'parent001',
            'status' => Tenant::STATUS_CONFIGURING,
        ]);
    }

    public function test_no_trata_una_sede_hija_como_colegio_del_panel(): void
    {
        $this->insertTenant('parent002', Tenant::TIPO_COLEGIO, null, Tenant::STATUS_ACTIVE);
        $this->insertTenant('child0002', Tenant::TIPO_SEDE, 'parent002', Tenant::STATUS_ACTIVE);

        $this->withHeader('Host', 'localhost')
            ->patchJson('/api/colegios/child0002/status', ['status' => Tenant::STATUS_SUSPENDED])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El estado de una sede se gestiona desde su colegio principal.');

        $this->assertDatabaseHas('tenants', [
            'id' => 'child0002',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
    }

    private function insertTenant(string $id, string $tipo, ?string $parentId, string $status): void
    {
        DB::table('tenants')->insert([
            'id' => $id,
            'tipo' => $tipo,
            'parent_id' => $parentId,
            'name' => 'Tenant '.$id,
            'slug' => 'tenant-'.$id,
            'plan' => Tenant::PLAN_ESENCIAL,
            'status' => $status,
            'calendar' => 'A',
            'locale' => 'es-CO',
            'timezone' => 'America/Bogota',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
