<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): jornadas académicas.
 *
 * Jornada (mañana/tarde/noche) pertenece a una sede. Define el rango de
 * tiempo en que opera y agrupa los bloques horarios (bloques_horarios).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jornadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sede_id')->constrained('sedes')->cascadeOnDelete();
            $table->string('nombre');                            // Mañana / Tarde / Noche / Única
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();
            $table->string('estado')->default('activa');         // activa|inactiva
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['sede_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jornadas');
    }
};
