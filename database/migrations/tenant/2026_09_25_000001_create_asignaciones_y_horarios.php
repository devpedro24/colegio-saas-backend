<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones_docentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos')->restrictOnDelete();
            $table->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $table->foreignId('docente_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX asignacion_activa_unica ON asignaciones_docentes (ano_lectivo_id, grupo_id, materia_id) WHERE deleted_at IS NULL');
        Schema::create('sesiones_horario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignacion_id')->nullable()->constrained('asignaciones_docentes')->restrictOnDelete();
            $table->foreignId('grupo_id')->nullable()->constrained('grupos')->restrictOnDelete();
            $table->foreignId('materia_id')->nullable()->constrained('materias')->restrictOnDelete();
            $table->foreignId('docente_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('dia', 12);
            $table->foreignId('bloque_horario_id')->nullable()->constrained('bloques_horarios')->restrictOnDelete();
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();
            $table->foreignId('espacio_fisico_id')->nullable()->constrained('espacios_fisicos')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['dia', 'bloque_horario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_horario');
        Schema::dropIfExists('asignaciones_docentes');
    }
};
