<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda la contrasena temporal del rector CIFRADA en la tabla central de
 * tenants. Permite que el superadmin "vea" la clave vigente (si el rector aun
 * no la cambio) sin guardar la clave en texto plano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('rector_temporary_password')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('rector_temporary_password');
        });
    }
};