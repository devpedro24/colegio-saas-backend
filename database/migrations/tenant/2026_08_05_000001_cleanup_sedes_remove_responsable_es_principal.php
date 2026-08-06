<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT: limpia la tabla `sedes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            if (Schema::hasColumn('sedes', 'responsable')) {
                $table->dropColumn('responsable');
            }
            if (Schema::hasColumn('sedes', 'es_principal')) {
                $table->dropColumn('es_principal');
            }
            if (! Schema::hasColumn('sedes', 'coordinador_name')) {
                $table->string('coordinador_name')->nullable()->after('telefono');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            if (Schema::hasColumn('sedes', 'coordinador_name')) {
                $table->dropColumn('coordinador_name');
            }
        });
        Schema::table('sedes', function (Blueprint $table) {
            if (! Schema::hasColumn('sedes', 'responsable')) {
                $table->string('responsable')->nullable();
            }
            if (! Schema::hasColumn('sedes', 'es_principal')) {
                $table->boolean('es_principal')->default(false);
            }
        });
    }
};
