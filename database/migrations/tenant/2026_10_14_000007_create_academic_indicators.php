<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicadores_configuracion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete()->unique();
            $table->string('modo', 20)->nullable(); // NULL means not configured.
            $table->json('opciones')->nullable();
            $table->decimal('valor_min', 8, 2)->nullable();
            $table->decimal('valor_max', 8, 2)->nullable();
            $table->decimal('umbral_aprobado', 8, 2)->nullable();
            $table->string('mostrar_boletin', 30)->default('todos');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('indicadores_base', function (Blueprint $table): void {
            $table->id();
            $table->text('texto');
            $table->unsignedSmallInteger('orden')->default(1);
            $table->string('estado', 20)->default('activo');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('indicadores_revisiones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('indicador_id')->constrained('indicadores_base')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('texto');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['indicador_id', 'version']);
        });
        Schema::create('indicadores_vinculos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('indicador_id')->constrained('indicadores_base')->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('indicadores_revisiones')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->foreignId('grado_id')->constrained('grados')->restrictOnDelete();
            $table->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $table->json('periodos_orden')->nullable(); // NULL = every real period.
            $table->string('estado', 20)->default('activo');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['indicador_id', 'ano_lectivo_id', 'grado_id', 'materia_id'], 'indicador_destino_unico');
            $table->index(['ano_lectivo_id', 'grado_id', 'materia_id', 'estado'], 'indicadores_contexto_idx');
        });
        Schema::create('indicadores_variantes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vinculo_id')->constrained('indicadores_vinculos')->restrictOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos')->restrictOnDelete();
            $table->unsignedTinyInteger('periodo_orden')->default(0); // 0 = all applicable periods.
            $table->text('texto');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['vinculo_id', 'grupo_id', 'periodo_orden'], 'indicador_variante_unica');
        });
        Schema::create('indicadores_valoraciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vinculo_id')->constrained('indicadores_vinculos')->restrictOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->string('modo', 20);
            $table->string('opcion_codigo', 40)->nullable();
            $table->decimal('valor_numerico', 8, 2)->nullable();
            $table->json('opcion_snapshot')->nullable();
            $table->text('texto_snapshot');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['vinculo_id', 'periodo_id', 'matricula_id'], 'indicador_valoracion_unica');
            $table->index(['grupo_id', 'periodo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicadores_valoraciones');
        Schema::dropIfExists('indicadores_variantes');
        Schema::dropIfExists('indicadores_vinculos');
        Schema::dropIfExists('indicadores_revisiones');
        Schema::dropIfExists('indicadores_base');
        Schema::dropIfExists('indicadores_configuracion');
    }
};
