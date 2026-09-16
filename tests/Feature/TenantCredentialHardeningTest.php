<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantCredentialHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_migracion_central_elimina_payloads_recuperables_y_su_columna(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->text('rector_temporary_password')->nullable();
        });

        $this->insertTenant('secret001', Tenant::STATUS_CONFIGURING);
        DB::table('tenants')->where('id', 'secret001')->update([
            'rector_temporary_password' => 'payload-cifrado-reversible',
        ]);

        $migration = require database_path('migrations/2026_08_14_000001_remove_recoverable_rector_passwords.php');
        $migration->up();

        $this->assertFalse(Schema::hasColumn('tenants', 'rector_temporary_password'));
        $this->assertDatabaseHas('tenants', ['id' => 'secret001']);
    }

    public function test_no_existe_ruta_para_recuperar_la_clave_temporal_del_rector(): void
    {
        $recoverableRoute = collect(Route::getRoutes()->getRoutes())->contains(
            fn ($route): bool => $route->uri() === 'api/colegios/{id}/rector-password'
                && in_array('GET', $route->methods(), true),
        );

        $this->assertFalse($recoverableRoute);
        $this->assertNotContains('rector_temporary_password', Tenant::getCustomColumns());
    }

    private function insertTenant(string $id, string $status): void
    {
        DB::table('tenants')->insert([
            'id' => $id,
            'tipo' => Tenant::TIPO_COLEGIO,
            'parent_id' => null,
            'name' => 'Colegio de prueba',
            'slug' => 'colegio-'.$id,
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
