<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): espacios físicos.
 *
 * Salones, laboratorios, biblioteca, auditorio, ... sobre los que se programan
 * clases (horarios, Bloque C). `tipo` es un catálogo fijo (RN-EF).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('espacios_fisicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->string('nombre');                 // "Salón 101", "Laboratorio de física", ...
            $table->string('tipo')->default('aula');  // aula|laboratorio|biblioteca|auditorio|patio|otro
            $table->unsignedSmallInteger('capacidad')->nullable();
            $table->string('ubicacion')->nullable();  // ala, piso, torre, ...
            $table->string('estado')->default('disponible'); // disponible|ocupado|mantenimiento|inactivo
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sede_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('espacios_fisicos');
    }
};
