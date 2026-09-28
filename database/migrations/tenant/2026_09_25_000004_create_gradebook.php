<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matriculas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estudiante_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->string('estado')->default('activa');
            $table->timestamps();
            $table->unique(['estudiante_id', 'ano_lectivo_id']);
        });
        Schema::create('componentes_evaluacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignacion_id')->constrained('asignaciones_docentes')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->string('nombre', 120);
            $table->string('modo')->default('SIMPLE_AVERAGE');
            $table->decimal('peso', 7, 4)->nullable();
            $table->timestamps();
            $table->unique(['asignacion_id', 'periodo_id', 'nombre'], 'componentes_evaluacion_nombre_unique');
        });
        Schema::create('actividades_evaluacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('componente_id')->constrained('componentes_evaluacion')->restrictOnDelete();
            $table->string('nombre', 160);
            $table->date('fecha');
            $table->decimal('peso', 7, 4)->nullable();
            $table->timestamps();
        });
        Schema::create('calificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->constrained('actividades_evaluacion')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->decimal('valor', 18, 8)->nullable();
            $table->string('observacion', 1000)->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['actividad_id', 'matricula_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calificaciones');
        Schema::dropIfExists('actividades_evaluacion');
        Schema::dropIfExists('componentes_evaluacion');
        Schema::dropIfExists('matriculas');
    }
};
