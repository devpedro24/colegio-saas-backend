<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados_manuales_anuales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('materia_id')->nullable()->constrained('materias')->restrictOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->restrictOnDelete();
            $table->decimal('valor', 14, 8)->nullable();
            $table->string('estado', 20);
            $table->text('motivo');
            $table->unsignedInteger('version');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['matricula_id', 'materia_id'], 'resultado_manual_anual_materia_unico');
            $table->unique(['matricula_id', 'area_id'], 'resultado_manual_anual_area_unico');
            $table->index(['materia_id', 'estado']);
            $table->index(['area_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados_manuales_anuales');
    }
};
