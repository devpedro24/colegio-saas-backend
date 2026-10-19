<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Do not silently destroy historical judgments in a college that may have used the old flow.
        foreach (['indicadores_valoraciones', 'indicadores_configuracion'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("{$table} contiene datos; respalda y migra su historial antes de retirarla.");
            }
        }

        Schema::dropIfExists('indicadores_valoraciones');
        Schema::dropIfExists('indicadores_configuracion');
        Permission::query()->where('name', 'indicadores.valoraciones.registrar')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // One-way retirement. Previous empty tables and grants are not reinstated automatically.
    }
};
