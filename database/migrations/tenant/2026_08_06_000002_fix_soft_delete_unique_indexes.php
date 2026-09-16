<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            ['users', 'users_email_unique', '(email)'],
            ['anos_lectivos', 'anos_lectivos_nombre_unique', '(nombre)'],
            ['periodos', 'periodos_ano_lectivo_id_orden_unique', '(ano_lectivo_id, orden)'],
            ['niveles', 'niveles_nivel_educativo_unique', '(nivel_educativo)'],
            ['grados', 'grados_nivel_id_nombre_unique', '(nivel_id, nombre)'],
            ['bloques_horarios', 'bloques_horarios_jornada_id_nombre_unique', '(jornada_id, nombre)'],
            ['metodos_aprobacion', 'metodos_aprobacion_active_unique', '(ano_lectivo_id, ambito)'],
            ['modelos_pedagogicos', 'modelos_pedagogicos_active_unique', '(ano_lectivo_id, nivel_educativo)'],
        ];

        if (Schema::hasTable('sedes')) {
            $definitions[] = ['sedes', 'sedes_nombre_unique', '(nombre)'];
        }
        if (Schema::hasColumn('jornadas', 'sede_id')) {
            $definitions[] = ['jornadas', 'jornadas_sede_id_nombre_unique', '(sede_id, nombre)'];
        }

        foreach ($definitions as [$table, $name, $columns]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            $this->dropUnique($table, $name);
            DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} {$columns} WHERE deleted_at IS NULL");
        }

        // La comprobacion de aplicacion no basta ante concurrencia.
        if (Schema::hasTable('anos_lectivos')) {
            DB::statement("CREATE UNIQUE INDEX anos_lectivos_single_en_curso ON anos_lectivos (estado) WHERE estado = 'en_curso' AND deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        foreach ([
            'users_email_unique', 'anos_lectivos_nombre_unique',
            'periodos_ano_lectivo_id_orden_unique', 'niveles_nivel_educativo_unique',
            'grados_nivel_id_nombre_unique', 'bloques_horarios_jornada_id_nombre_unique',
            'metodos_aprobacion_active_unique', 'modelos_pedagogicos_active_unique',
            'sedes_nombre_unique', 'jornadas_sede_id_nombre_unique',
            'anos_lectivos_single_en_curso',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }

    private function dropUnique(string $table, string $name): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
        DB::statement("DROP INDEX IF EXISTS {$name}");
    }
};
