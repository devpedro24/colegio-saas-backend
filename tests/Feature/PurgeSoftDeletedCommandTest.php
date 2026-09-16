<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeSoftDeletedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_devuelve_fallo_si_un_tenant_no_puede_purgarse(): void
    {
        DB::table('tenants')->insert([
            'id' => 'broken0001',
            'tipo' => Tenant::TIPO_COLEGIO,
            'parent_id' => null,
            'name' => 'Tenant sin base disponible',
            'slug' => 'tenant-sin-base',
            'plan' => Tenant::PLAN_ESENCIAL,
            'status' => Tenant::STATUS_CONFIGURING,
            'calendar' => 'A',
            'locale' => 'es-CO',
            'timezone' => 'America/Bogota',
            'data' => json_encode(['tenancy_db_name' => 'sqlite_inexistente_para_purge']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('purge:soft-deleted', ['--retention' => 30]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Purga incompleta: 1 tenant(s) fallaron', Artisan::output());
        $this->assertDatabaseHas('platform_audit_logs', [
            'accion' => 'PURGE',
            'recurso' => 'soft_deleted',
        ]);
    }
}
