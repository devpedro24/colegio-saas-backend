<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparaciones_evaluacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('materia_curricular_id')->constrained('materias_curriculares')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['materia_curricular_id', 'periodo_id'], 'preparacion_curriculo_periodo_unique');
        });
        Schema::create('componentes_preparados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preparacion_id')->constrained('preparaciones_evaluacion')->restrictOnDelete();
            $table->string('nombre', 120);
            $table->string('modo', 24);
            $table->decimal('peso', 7, 4)->nullable();
            $table->timestamps();
            $table->unique(['preparacion_id', 'nombre']);
        });
        Schema::create('actividades_preparadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('componente_id')->constrained('componentes_preparados')->restrictOnDelete();
            $table->string('nombre', 160);
            $table->date('fecha');
            $table->decimal('peso', 7, 4)->nullable();
            $table->timestamps();
            $table->unique(['componente_id', 'nombre']);
        });
        Schema::table('componentes_evaluacion', function (Blueprint $table) {
            $table->foreignId('componente_preparado_id')->nullable()
                ->constrained('componentes_preparados')->restrictOnDelete();
            $table->unique(['asignacion_id', 'periodo_id', 'componente_preparado_id'],
                'componente_preparado_aplicado_unique');
        });
    }

    public function down(): void
    {
        Schema::table('componentes_evaluacion', function (Blueprint $table) {
            $table->dropUnique('componente_preparado_aplicado_unique');
            $table->dropConstrainedForeignId('componente_preparado_id');
        });
        Schema::dropIfExists('actividades_preparadas');
        Schema::dropIfExists('componentes_preparados');
        Schema::dropIfExists('preparaciones_evaluacion');
    }
};
