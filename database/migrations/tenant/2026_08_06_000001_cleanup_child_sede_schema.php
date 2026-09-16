<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (tenant()?->tipo !== Tenant::TIPO_SEDE) {
            return;
        }

        foreach ([
            ['jornadas', 'jornadas_sede_id_nombre_unique'],
            ['espacios_fisicos', 'espacios_fisicos_sede_id_nombre_unique'],
        ] as [$table, $index]) {
            // En PostgreSQL el indice UNIQUE pertenece a una restriccion y no
            // puede eliminarse directamente. Instalaciones SQLite usan el
            // DROP INDEX tolerante como fallback.
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$index}");
            }
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        foreach (['jornadas', 'grupos', 'espacios_fisicos'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'sede_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['sede_id']);
            });
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('sede_id');
            });
        }

        Schema::dropIfExists('sedes');
    }

    public function down(): void
    {
        // Irreversible por diseno: volver a crear una jerarquia recursiva en la
        // sede hija violaria la frontera de tenancy. Los datos locales quedan.
    }
};
