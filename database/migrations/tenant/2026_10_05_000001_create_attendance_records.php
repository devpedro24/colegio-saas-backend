<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_clases', function (Blueprint $table) {
            $table->id();
            // Una ocurrencia fechada por cada franja explícita, no por duración en minutos.
            $table->foreignId('sesion_horario_id')->constrained('sesiones_horario')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->foreignId('asignacion_id')->constrained('asignaciones_docentes')->restrictOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos')->restrictOnDelete();
            $table->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $table->foreignId('docente_programado_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('espacio_fisico_id')->nullable()->constrained('espacios_fisicos')->restrictOnDelete();
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['sesion_horario_id', 'fecha']);
            $table->index(['asignacion_id', 'fecha']);
            $table->index(['grupo_id', 'fecha']);
        });

        Schema::create('asistencia_marcas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asistencia_clase_id')->constrained('asistencia_clases')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->string('estado', 16); // presente, ausente, tarde, justificada
            $table->foreignId('registrado_por_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['asistencia_clase_id', 'matricula_id']);
            $table->index(['matricula_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_marcas');
        Schema::dropIfExists('asistencia_clases');
    }
};
