<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): periodos académicos (periodos-academicos.md).
 *
 * Cada periodo pertenece a un año lectivo. Los periodos son contiguos y quedan
 * dentro del rango de fechas del año (RN-PA-003): la fecha de inicio del periodo
 * N+1 es el día siguiente al cierre del periodo N.
 *
 * Estados (FSM): planificado → abierto → cerrado.
 *   - Un periodo cerrado es inmutable (RN-PA-006).
 *
 * `orden` es la posición del periodo dentro del año (1..N, +1 si hay quinto
 * periodo); único por año lectivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->cascadeOnDelete();
            $table->string('nombre');
            $table->unsignedTinyInteger('orden');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('peso', 5, 2)->nullable();                // peso porcentual del periodo (opcional)
            $table->string('estado')->default('planificado');         // planificado|abierto|cerrado
            $table->timestamps();
            $table->softDeletes();

            // El orden es único dentro de cada año lectivo.
            $table->unique(['ano_lectivo_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos');
    }
};
