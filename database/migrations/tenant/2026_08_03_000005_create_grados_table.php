<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): grados académicos.
 *
 * Grado (transición, 6.º, 7.º, ...) pertenece a un nivel educativo. El grupo
 * concreto de un año lectivo se modela en `grupos` (RN-JO-002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nivel_id')->constrained('niveles')->cascadeOnDelete();
            $table->string('nombre');                      // "Transición", "Sexto", "6.º", ...
            $table->string('codigo')->nullable();          // código institucional corto (p.ej. "6")
            $table->unsignedTinyInteger('orden')->default(0);
            $table->string('estado')->default('activo');   // activo|inactivo
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['nivel_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grados');
    }
};
