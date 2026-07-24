<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matriz GLOBAL por defecto (central): clasificacion de cada celda rol x permiso.
 *
 * Convencion: solo se guardan filas para celdas 'structural' o 'configurable'.
 * La AUSENCIA de fila = 'denied' (el rol no tiene ni puede tener ese permiso),
 * igual que en App\Rbac\PermissionMatrix (rol ausente de cells = denegado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_matrix', function (Blueprint $table) {
            $table->id();
            $table->string('role_key');
            $table->string('permission_key');
            $table->string('type');                       // structural | configurable
            $table->string('level')->nullable();          // crud|editar|ver|... si estructural
            $table->boolean('default_granted')->default(false); // otorgado por defecto (configurable)
            $table->timestamps();

            $table->unique(['role_key', 'permission_key']);
            $table->index('permission_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_matrix');
    }
};
