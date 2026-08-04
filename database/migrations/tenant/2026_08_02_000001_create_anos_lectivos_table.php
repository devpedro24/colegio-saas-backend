<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BD DEL TENANT (colegio): años lectivos (RN-PA-001..008).
 *
 * Un año lectivo agrupa los periodos académicos del colegio. Vive en la BD
 * aislada del tenant (RN-AI-001), por lo que la unicidad del nombre es local
 * al colegio.
 *
 * Estados (FSM): planificado → en_curso → cerrado → archivado.
 *   - Solo un año puede estar 'en_curso' a la vez (RN-PA-001); el siguiente
 *     puede estar 'planificado' (caso Calendario B en junio).
 *
 * tipo_calendario:
 *   - 'A': febrero–noviembre; nombre "AAAA" (p.ej. "2026").
 *   - 'B': septiembre–junio (cruza año); nombre "AAAA-AAAA" (p.ej. "2025-2026").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anos_lectivos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');                                 // "2026" (A) | "2025-2026" (B) — RN-PA-002
            $table->string('tipo_calendario');                        // A | B — inmutable si hay años activos/cerrados (RN-PA-007)
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->unsignedTinyInteger('num_periodos')->default(4);
            $table->boolean('tiene_quinto_periodo')->default(false);  // periodo sumatorio opcional (RN-PA-008)
            $table->string('estado')->default('planificado');         // planificado|en_curso|cerrado|archivado
            $table->timestamps();
            $table->softDeletes();

            // El nombre del año lectivo es único dentro del colegio.
            $table->unique('nombre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anos_lectivos');
    }
};
