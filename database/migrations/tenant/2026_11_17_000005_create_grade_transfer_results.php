<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matricula_resultados_traslado', function (Blueprint $table) {
            $table->id();
            $table->char('token', 24)->unique();
            $table->foreignId('movimiento_id')->constrained('matricula_movimientos')->restrictOnDelete();
            $table->foreignId('grupo_origen_id')->constrained('grupos')->restrictOnDelete();
            $table->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->jsonb('resultado')->nullable();
            $table->string('motivo')->nullable();
            $table->unique(['movimiento_id', 'materia_id', 'periodo_id'], 'traslado_materia_periodo_unico');
        });
        Schema::table('matricula_valoraciones_ingreso', function (Blueprint $table) {
            $table->foreignId('resultado_traslado_id')->nullable()->constrained('matricula_resultados_traslado')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matricula_valoraciones_ingreso', fn (Blueprint $table) => $table->dropConstrainedForeignId('resultado_traslado_id'));
        Schema::dropIfExists('matricula_resultados_traslado');
    }
};
