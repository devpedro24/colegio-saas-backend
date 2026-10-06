<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('aulas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos')->restrictOnDelete();
            $table->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $table->string('portada_token', 24)->nullable();
            $table->timestamps();
            $table->unique(['grupo_id', 'materia_id']);
        });
        Schema::create('aula_secciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aula_id')->constrained('aulas')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->foreignId('preinforme_id')->nullable()->constrained('preinformes')->restrictOnDelete();
            $table->foreignId('seccion_origen_id')->nullable()->constrained('aula_secciones')->nullOnDelete();
            $table->string('titulo', 160);
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('visible_estudiantes')->default(false);
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['aula_id', 'periodo_id', 'orden']);
            $table->unique(['aula_id', 'seccion_origen_id']);
        });
        Schema::create('aula_recursos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seccion_id')->constrained('aula_secciones')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->string('titulo', 160);
            $table->json('contenido')->nullable();
            $table->json('configuracion')->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->boolean('visible_estudiantes')->default(false);
            $table->boolean('calificable')->default(false);
            $table->boolean('llevar_planilla')->default(false);
            $table->foreignId('actividad_id')->nullable()->unique()->constrained('actividades_evaluacion')->restrictOnDelete();
            $table->decimal('peso', 7, 4)->nullable();
            $table->dateTime('disponible_desde')->nullable();
            $table->dateTime('disponible_hasta')->nullable();
            $table->dateTime('fecha_limite')->nullable();
            $table->string('zona_publicacion', 64)->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recurso_origen_id')->nullable()->constrained('aula_recursos')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['seccion_id', 'orden']);
        });
        Schema::create('aula_adjuntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurso_id')->nullable()->constrained('aula_recursos')->restrictOnDelete();
            $table->foreignId('entrega_id')->nullable();
            $table->string('archivo_token', 24);
            $table->string('nombre', 255);
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['recurso_id', 'entrega_id']);
        });
        Schema::create('aula_entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurso_id')->constrained('aula_recursos')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->text('texto')->nullable();
            $table->decimal('nota', 18, 8)->nullable();
            $table->foreignId('escala_opcion_id')->nullable()->constrained('escala_opciones')->restrictOnDelete();
            $table->text('retroalimentacion')->nullable();
            $table->string('estado', 20)->default('entregada');
            $table->dateTime('entregada_at')->nullable();
            $table->foreignId('calificada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['recurso_id', 'matricula_id']);
        });
        Schema::table('aula_adjuntos', fn (Blueprint $table) => $table->foreign('entrega_id')->references('id')->on('aula_entregas')->restrictOnDelete());
        Schema::create('aula_preguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurso_id')->constrained('aula_recursos')->restrictOnDelete();
            $table->string('tipo', 24);
            $table->text('enunciado');
            $table->json('opciones')->nullable();
            $table->json('respuesta_correcta')->nullable();
            $table->decimal('puntos', 8, 3)->default(1);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });
        Schema::create('aula_intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurso_id')->constrained('aula_recursos')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->string('estado', 20)->default('en_curso');
            $table->dateTime('iniciado_at');
            $table->dateTime('vence_at')->nullable();
            $table->dateTime('finalizado_at')->nullable();
            $table->unsignedInteger('incidentes')->default(0);
            $table->json('respuestas')->nullable();
            $table->decimal('nota', 18, 8)->nullable();
            $table->foreignId('escala_opcion_id')->nullable()->constrained('escala_opciones')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['recurso_id', 'matricula_id', 'numero']);
        });
        Schema::create('aula_incidentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intento_id')->constrained('aula_intentos')->restrictOnDelete();
            $table->string('tipo', 40);
            $table->string('detalle', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['aula_incidentes', 'aula_intentos', 'aula_preguntas', 'aula_adjuntos', 'aula_entregas', 'aula_recursos', 'aula_secciones', 'aulas'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
