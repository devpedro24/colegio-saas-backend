<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escala valorativa del COLEGIO (BD del tenant) — bloque 4 de configuracion.
 *
 * Define como se califica: numerica o por imagenes, con su rango, cantidad de
 * decimales y la nota a partir de la cual se aprueba. Se versiona por ano
 * lectivo (columna `ano_lectivo_id`).
 *
 * RN-CC-003: la escala puede variar por nivel. Por eso `nivel_id` es nullable
 * (una escala con `nivel_id` NULL aplica a todo el colegio); la FK a la tabla
 * de niveles se agregara en la Fase 1 Bloque B, por ahora queda sin constrained.
 *
 * Lleva soft-deletes para conservar historicos de calificacion coherentes con
 * la escala vigente cuando se registro cada nota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalas_valorativas', function (Blueprint $table) {
            $table->id();

            // Version por ano lectivo (la tabla la crea AGENT-A).
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->cascadeOnDelete();

            // Variacion por nivel educativo (RN-CC-003): NULL = aplica a todo el colegio.
            $table->string('nivel_educativo')->nullable();        // preescolar | primaria | secundaria | media

            $table->string('nombre');                             // etiqueta de la escala
            $table->string('tipo')->default('numerica');          // numerica | imagenes
            $table->decimal('valor_min', 5, 2)->nullable();
            $table->decimal('valor_max', 5, 2)->nullable();
            $table->unsignedTinyInteger('decimales')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('ano_lectivo_id');
            $table->index('nivel_educativo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalas_valorativas');
    }
};
