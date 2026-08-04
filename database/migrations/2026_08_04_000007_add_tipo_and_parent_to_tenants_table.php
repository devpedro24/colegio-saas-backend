<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modela sedes como tenants hijos (padre = colegio).
 *
 * - `tipo`     : `colegio` (tenant principal) o `sede` (tenant hijo).
 * - `parent_id`: FK al tenant padre (el colegio) para sedes; NULL en colegios.
 *
 * Cada sede adicional del colegio se provisiona como tenant hijo con su
 * propia BD, RBAC, usuarios y subdominio `<slug>.<subdominio-colegio>`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('tipo')->default('colegio')->after('id');
            $table->string('parent_id')->nullable()->after('tipo');
            $table->foreign('parent_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
            $table->dropColumn('tipo');
        });
    }
};
