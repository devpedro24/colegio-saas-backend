<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): sedes (multi-sede).
 *
 * Un colegio puede operar una o más sedes (campus). Cada sede es la raíz de
 * la jerarquía organizacional Sede→Jornada→Nivel→Grado→Grupo.
 *
 * Las sedes SOLO viven en la BD del colegio principal: en la BD de un tenant
 * hijo (`tipo = sede`) esta tabla NO se crea. El listado de sedes de ese
 * tenant devuelve vacío (SedeController contempla la tabla ausente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sedes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('direccion')->nullable();
            $table->string('telefono')->nullable();
            $table->string('responsable')->nullable();
            $table->boolean('es_principal')->default(false);  // sede principal del colegio
            $table->string('estado')->default('activa');      // activa|inactiva
            $table->timestamps();
            $table->softDeletes();

            $table->unique('nombre')->whereNull('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sedes');
    }
};
