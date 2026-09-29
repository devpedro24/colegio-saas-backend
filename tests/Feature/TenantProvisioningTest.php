<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\VerifyTenantMigrations;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Services\SedeProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Tests\TestCase;

class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_school_is_migrated_before_rector_and_domain_are_published(): void
    {
        $this->seed(DatabaseSeeder::class);

        ['tenant' => $tenant] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio nuevo',
            'slug' => 'colegio-provision-'.uniqid(),
            'rector_email' => 'rector@nuevo.test',
        ]);

        $this->assertSame(Tenant::STATUS_CONFIGURING, $tenant->fresh()->status);
        $this->assertNotNull($tenant->domains()->first());

        $tenant->run(function () use ($tenant): void {
            (new VerifyTenantMigrations($tenant))->verifyCurrentDatabase();
            $rector = User::where('email', 'rector@nuevo.test')->firstOrFail();
            $this->assertTrue($rector->hasRole('rector'));
        });

        ['tenant' => $sede] = app(SedeProvisioner::class)->provision($tenant, [
            'name' => 'Sede Norte',
            'slug' => 'norte',
            'coordinador_email' => 'coordinador@nuevo.test',
            'heredar' => false,
        ]);
        $this->assertSame(Tenant::STATUS_ACTIVE, $sede->fresh()->status);
        $this->assertNotNull($sede->domains()->first());
        $sede->run(function () use ($sede): void {
            (new VerifyTenantMigrations($sede))->verifyCurrentDatabase();
            $this->assertTrue(User::where('email', 'coordinador@nuevo.test')->firstOrFail()->hasRole('coord_combinado'));
        });
    }

    public function test_failed_provisioning_keeps_tenant_unpublished(): void
    {
        $slug = 'sin-catalogo-'.uniqid();
        try {
            app(TenantProvisioner::class)->provision([
                'name' => 'Colegio sin catálogo', 'slug' => $slug,
                'rector_email' => 'rector@fallido.test',
            ]);
            $this->fail('La provisión debió fallar sin el catálogo central de roles.');
        } catch (RoleDoesNotExist) {
            $partial = Tenant::where('slug', $slug)->firstOrFail();
            $this->assertSame(Tenant::STATUS_PROVISIONING, $partial->status);
            $this->assertSame(0, $partial->domains()->count());
            $this->assertNull(tenant());
        }
    }
}
