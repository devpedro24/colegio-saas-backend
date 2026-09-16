<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra el hueco de unicidad de PostgreSQL para columnas nullable.
 *
 * En un UNIQUE convencional, PostgreSQL considera distintos dos NULL. Por
 * eso era posible crear varios grupos con jornada NULL o varios espacios sin
 * sede con el mismo nombre. COALESCE normaliza ese alcance y el predicado
 * conserva la reutilizacion de la clave despues de un soft-delete.
 */
return new class extends Migration
{
    private const GROUPS_ORIGINAL = 'grupos_grado_id_ano_lectivo_id_jornada_id_nombre_unique';

    private const GROUPS_ACTIVE = 'grupos_active_scope_unique';

    private const SPACES_ORIGINAL = 'espacios_fisicos_sede_id_nombre_unique';

    private const SPACES_ACTIVE = 'espacios_fisicos_active_scope_unique';

    public function up(): void
    {
        if ($this->hasColumns('grupos', [
            'grado_id', 'ano_lectivo_id', 'jornada_id', 'nombre', 'deleted_at',
        ])) {
            $this->dropOriginalUnique('grupos', self::GROUPS_ORIGINAL);
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON grupos (grado_id, ano_lectivo_id, COALESCE(jornada_id, 0), nombre) WHERE deleted_at IS NULL',
                self::GROUPS_ACTIVE,
            ));
        }

        if ($this->hasColumns('espacios_fisicos', ['nombre', 'deleted_at'])) {
            $this->dropOriginalUnique('espacios_fisicos', self::SPACES_ORIGINAL);

            $scope = Schema::hasColumn('espacios_fisicos', 'sede_id')
                ? 'COALESCE(sede_id, 0), nombre'
                : 'nombre';

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON espacios_fisicos (%s) WHERE deleted_at IS NULL',
                self::SPACES_ACTIVE,
                $scope,
            ));
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::GROUPS_ACTIVE);
        DB::statement('DROP INDEX IF EXISTS '.self::SPACES_ACTIVE);

        // SQLite conserva la restriccion inline original. Solo PostgreSQL la
        // elimino en up(), por lo que solo alli hace falta reconstruirla.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if ($this->hasColumns('grupos', [
            'grado_id', 'ano_lectivo_id', 'jornada_id', 'nombre',
        ])) {
            DB::statement(sprintf(
                'ALTER TABLE grupos ADD CONSTRAINT %s UNIQUE (grado_id, ano_lectivo_id, jornada_id, nombre)',
                self::GROUPS_ORIGINAL,
            ));
        }

        if ($this->hasColumns('espacios_fisicos', ['sede_id', 'nombre'])) {
            DB::statement(sprintf(
                'ALTER TABLE espacios_fisicos ADD CONSTRAINT %s UNIQUE (sede_id, nombre)',
                self::SPACES_ORIGINAL,
            ));
        }
    }

    /** @param list<string> $columns */
    private function hasColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function dropOriginalUnique(string $table, string $name): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }

        DB::statement("DROP INDEX IF EXISTS {$name}");
    }
};
