<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): niveles educativos.
 *
 * Catálogo del colegio (preescolar, primaria, secundaria, media, ...).
 * `nivel_educativo` es la clave estable usada por otras tablas que ya
 * referencian el nivel como string (escalas_valorativas, modelos_pedagogicos)
 * — aquí se liga el string a una fila real (Bloque B).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('niveles', function (Blueprint $table) {
            $table->id();
            $table->string('nivel_educativo')->unique();  // clave estable: preescolar|primaria|secundaria|media|...
            $table->string('nombre');                     // "Preescolar", "Educación Básica Primaria", ...
            $table->unsignedTinyInteger('orden')->default(0);
            $table->string('estado')->default('activo');  // activo|inactivo
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('niveles');
    }
};
