<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados_manuales_materia', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('asignacion_id')->constrained('asignaciones_docentes')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->decimal('valor', 14, 8)->nullable();
            $table->string('estado', 20); // vigente o anulado
            $table->text('motivo');
            $table->unsignedInteger('version');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['matricula_id', 'asignacion_id', 'periodo_id'], 'resultado_manual_materia_unico');
            $table->index(['asignacion_id', 'periodo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados_manuales_materia');
    }
};
