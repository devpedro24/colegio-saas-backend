<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelo pedagogico del COLEGIO (BD del tenant) — bloque 6 de configuracion.
 *
 * Describe la organizacion academica del nivel: si hay docente unico, salon
 * fijo y director de grupo. Se versiona por ano lectivo.
 *
 * Aplica por nivel, por eso `nivel_id` es nullable (un modelo con `nivel_id`
 * NULL aplica a todo el colegio); la FK a la tabla de niveles se agregara en la
 * Fase 1 Bloque B, por ahora queda sin constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modelos_pedagogicos', function (Blueprint $table) {
            $table->id();

            // Version por ano lectivo (la tabla la crea AGENT-A).
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos');

            // Aplica por nivel educativo (NULL no esperado: el frontend siempre envia nivel).
            $table->string('nivel_educativo')->nullable();        // preescolar | primaria | secundaria | media

            $table->boolean('docente_unico')->default(false);
            $table->boolean('salon_fijo')->default(true);
            $table->boolean('tiene_director_grupo')->default(true);

            $table->timestamps();

            $table->index('ano_lectivo_id');
            $table->index('nivel_educativo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modelos_pedagogicos');
    }
};
