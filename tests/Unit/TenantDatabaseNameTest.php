<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Tenant;
use App\Tenancy\ShortTenantIdGenerator;
use App\Tenancy\TenantDatabaseName;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantDatabaseNameTest extends TestCase
{
    private function tenantWith(string $name, string $id): Tenant
    {
        $tenant = new Tenant;
        $tenant->setRawAttributes([
            'name' => $name,
        ]);
        $tenant->id = $id;

        return $tenant;
    }

    #[Test]
    public function el_id_corto_tiene_10_caracteres_alnum_minuscula(): void
    {
        foreach (range(1, 50) as $i) {
            $id = ShortTenantIdGenerator::generate(null);
            $this->assertSame(10, strlen($id));
            $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $id);
        }
    }

    #[Test]
    public function nombre_bd_sigue_el_patron_tenant_nombre_id(): void
    {
        $tenant = $this->tenantWith('Colegio San Jose', 'k7x2m9p4qr');

        $this->assertSame(
            'tenant_colegio_san_jose_k7x2m9p4qr',
            TenantDatabaseName::for($tenant)
        );
    }

    #[Test]
    public function nombre_bd_con_fallback_y_minusculas(): void
    {
        $tenant = $this->tenantWith('Padre Arturo 2', 'a1b2c3d4e5');

        $this->assertSame(
            'tenant_padre_arturo_2_a1b2c3d4e5',
            TenantDatabaseName::for($tenant)
        );
    }

    #[Test]
    public function nombre_bd_con_id_uuid_usa_sufijo_corto(): void
    {
        $tenant = $this->tenantWith('Prueba', '123e4567-e89b-12d3-a456-426614174000');

        $this->assertSame(
            'tenant_prueba_123e4567e8',
            TenantDatabaseName::for($tenant)
        );
    }

    #[Test]
    public function nombre_bd_trunca_a_63_bytes_conservando_el_id(): void
    {
        $tenant = $this->tenantWith(
            'Colegio Muy Largo de Nombres Extensos para Probar el Truncamiento',
            '0123456789'
        );

        $name = TenantDatabaseName::for($tenant);

        $this->assertLessThanOrEqual(63, strlen($name));
        $this->assertStringEndsWith('_0123456789', $name);
        $this->assertStringStartsWith('tenant_', $name);
    }

    #[Test]
    public function nombre_bd_de_sede_incluye_el_slug_del_colegio_padre(): void
    {
        $colegio = $this->tenantWith('Colegio San Jose', 'k7x2m9p4qr');
        $colegio->exists = true;

        $sede = $this->tenantWith('Sede Norte', 'a1b2c3d4e5');
        $sede->setRelation('parent', $colegio);

        $this->assertSame(
            'tenant_colegio_san_jose_sede_norte_a1b2c3d4e5',
            TenantDatabaseName::for($sede)
        );

        $this->assertSame(
            'tenant_colegio_san_jose_k7x2m9p4qr',
            TenantDatabaseName::for($colegio)
        );
    }

    #[Test]
    public function nombre_bd_sin_tenant_usa_valores_por_defecto(): void
    {
        $name = TenantDatabaseName::for(null);

        $this->assertStringStartsWith('tenant_colegio_', $name);
        $this->assertMatchesRegularExpression('/^tenant_colegio_[a-z0-9]{10}$/', $name);
    }
}
