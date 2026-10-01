<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recuperaciones_academicas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('asignacion_id')->constrained('asignaciones_docentes')->restrictOnDelete();
            $table->foreignId('periodo_id')->nullable()->constrained('periodos')->restrictOnDelete();
            // PostgreSQL permite múltiples NULL en índices únicos: una clave explícita
            // evita habilitaciones anuales duplicadas bajo concurrencia.
            $table->string('alcance', 40);
            $table->string('tipo', 20);
            $table->string('estado', 20)->default('pendiente');
            $table->string('politica', 32);
            $table->string('valor_original_exacto', 80);
            $table->decimal('nota_recuperacion', 18, 8)->nullable();
            $table->decimal('nota_manual', 18, 8)->nullable();
            $table->string('valor_efectivo_exacto', 80)->nullable();
            $table->text('plan_mejoramiento')->nullable();
            $table->text('motivo')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['matricula_id', 'asignacion_id', 'alcance'], 'recuperacion_unica_por_ambito');
            $table->index(['ano_lectivo_id', 'estado']);
            $table->index(['asignacion_id', 'periodo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recuperaciones_academicas');
    }
};
