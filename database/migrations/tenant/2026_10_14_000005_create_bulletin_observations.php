<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_boletines', function (Blueprint $table): void {
            $table->id();
            $table->boolean('requiere_observacion_final')->default(false);
            $table->boolean('docente_edita_indicadores_grupo')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('observaciones_boletin', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            // final or periodo:<internal id>; avoids NULL uniqueness ambiguity.
            $table->string('contexto', 40);
            $table->foreignId('periodo_id')->nullable()->constrained('periodos')->restrictOnDelete();
            $table->text('contenido');
            $table->string('estado', 20)->default('borrador');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('aprobado_en')->nullable();
            $table->timestamps();
            $table->unique(['matricula_id', 'contexto']);
            $table->index(['periodo_id', 'estado']);
        });
        Schema::create('observaciones_boletin_revisiones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observacion_id')->constrained('observaciones_boletin')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('contenido');
            $table->string('estado', 20);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['observacion_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observaciones_boletin_revisiones');
        Schema::dropIfExists('observaciones_boletin');
        Schema::dropIfExists('configuracion_boletines');
    }
};
