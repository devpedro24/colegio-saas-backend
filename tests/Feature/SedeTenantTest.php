<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\SedeProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sede como tenant hijo: cuarentena (bajarPorId) y captura de configuracion.
 *
 * El flujo COMPLETO de provisioning (crear BD del hijo, sembrar RBAC,
 * coordinar usuarios) se valida con probes reales sobre PostgreSQL, porque
 * aqui los tests corren con sqlite en memoria.
 */
class SedeTenantTest extends TestCase
{
    use RefreshDatabase;

    private function makeSedeTenant(string $status = Tenant::STATUS_CONFIGURING): Tenant
    {
        /** @var Tenant $colegio */
        $colegio = Tenant::create([
            'name' => 'Colegio sede',
            'slug' => 'sede-'.uniqid(),
            'plan' => Tenant::PLAN_ESENCIAL,
            'status' => Tenant::STATUS_ACTIVE,
            'tipo' => Tenant::TIPO_COLEGIO,
        ]);

        /** @var Tenant $sede */
        $sede = Tenant::create([
            'name' => 'Sede norte',
            'slug' => 'norte-'.uniqid(),
            'plan' => Tenant::PLAN_ESENCIAL,
            'status' => $status,
            'tipo' => Tenant::TIPO_SEDE,
            'parent_id' => $colegio->id,
        ]);

        $sede->domains()->create(['domain' => $sede->slug.'.'.$colegio->slug]);

        return $sede;
    }

    public function test_sede_es_tenant_hijo_con_parent_y_dominio_anidado(): void
    {
        $sede = $this->makeSedeTenant();

        $this->assertSame(Tenant::TIPO_SEDE, $sede->tipo);
        $this->assertNotNull($sede->parent_id);

        $this->assertSame($sede->parent_id, $sede->parent->id);
        $this->assertTrue($sede->parent->sedes()->where('id', $sede->id)->exists());

        $this->assertStringContainsString('.', $sede->domains()->first()->domain);
    }

    public function test_bajar_sede_lleva_a_cuarentena_y_elimina_el_subdominio(): void
    {
        $sede = $this->makeSedeTenant();

        (new SedeProvisioner())->bajarPorId($sede->id);

        $this->assertSame(Tenant::STATUS_IN_RETENTION, $sede->fresh()->status);
        $this->assertSame(0, $sede->fresh()->domains()->count());
    }

    public function test_bajar_sede_con_id_null_no_falla(): void
    {
        (new SedeProvisioner())->bajarPorId(null);

        $this->assertTrue(true);
    }
}
