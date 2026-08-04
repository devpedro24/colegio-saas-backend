<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doble identificador de auditoria durante la SUPLANTACION (RN-LA-002).
 *
 * Cuando el superadministrador actua DENTRO de un colegio suplantando al rector,
 * la accion se audita en el log del colegio con el actor visible (el usuario
 * sombra 'rector') PERO conservando quien esta realmente detras: el email del
 * superadmin en `impersonated_by`. En operaciones normales del colegio la
 * columna queda NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('impersonated_by')->nullable()->after('actor_rol');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('impersonated_by');
        });
    }
};
