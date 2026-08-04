<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): bloques horarios de cada jornada.
 *
 * Segmentos de tiempo dentro de una jornada (bloque 1, descanso, bloque 2, ...)
 * sobre los que se arma el horario semanal (Bloque C).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloques_horarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->constrained('jornadas')->cascadeOnDelete();
            $table->string('nombre');              // "Bloque 1", "Descanso", ...
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->boolean('es_descanso')->default(false);
            $table->unsignedTinyInteger('orden')->default(0);
            $table->string('estado')->default('activo');  // activo|inactivo
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['jornada_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bloques_horarios');
    }
};
