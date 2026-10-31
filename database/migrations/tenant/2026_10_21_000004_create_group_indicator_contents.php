<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicadores_contenidos_grupo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos')->restrictOnDelete();
            $table->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->longText('texto_html');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['grupo_id', 'materia_id', 'periodo_id'], 'indicador_contenido_grupo_unico');
            $table->index(['ano_lectivo_id', 'grupo_id', 'periodo_id'], 'indicador_contenido_contexto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicadores_contenidos_grupo');
    }
};
