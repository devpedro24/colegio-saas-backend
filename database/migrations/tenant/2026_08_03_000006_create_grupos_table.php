<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): grupos (curso concreto).
 *
 * RN-JO-002: un grupo está ligado a grado + año lectivo (+ jornada).
 * Es la unidad que agrupa estudiantes y sobre la que se asignan docentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grado_id')->constrained('grados')->cascadeOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->cascadeOnDelete();
            $table->foreignId('jornada_id')->nullable()->constrained('jornadas')->nullOnDelete();
            $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
            $table->string('nombre');                      // "6-A", "6-B", "A", ...
            $table->unsignedSmallInteger('cupo_maximo')->nullable();  // tope de estudiantes (RN-MA-001)
            $table->string('estado')->default('activo');   // activo|inactivo
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['grado_id', 'ano_lectivo_id', 'jornada_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos');
    }
};
