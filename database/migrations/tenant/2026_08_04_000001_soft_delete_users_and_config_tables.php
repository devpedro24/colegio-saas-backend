<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): soft-delete universal (RG-004, RN-BR-005).
 *
 * - `users`: el ciclo de vida del usuario termina en borrado LOGICO (estado
 *   `deleted` o `deleted_at`); la fila persiste durante la retencion y luego
 *   se purga fisicamente.
 * - `metodos_aprobacion` y `modelos_pedagogicos`: tablas de configuracion que
 *   sus modelos ya declaran como soft-delete; se les agrega la columna aqui
 *   (migracion aditiva, no rompe los tenants con datos existentes).
 *
 * `datos_institucionales` es singleton y no tiene borrado; no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('metodos_aprobacion', 'deleted_at')) {
            Schema::table('metodos_aprobacion', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('modelos_pedagogicos', 'deleted_at')) {
            Schema::table('modelos_pedagogicos', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'metodos_aprobacion', 'modelos_pedagogicos'] as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropSoftDeletes();
                });
            }
        }
    }
};
