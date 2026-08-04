<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): sedes como tenants hijos.
 *
 * - `tenant_id`: id del tenant (BD central) provisionado para esta sede.
 *   NULL = sede principal del colegio (el propio colegio, sin tenant hijo).
 * - `coordinador_email`: correo del usuario coordinador creado en el hijo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('responsable');
            $table->string('coordinador_email')->nullable()->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            $table->dropColumn('coordinador_email');
            $table->dropColumn('tenant_id');
        });
    }
};
