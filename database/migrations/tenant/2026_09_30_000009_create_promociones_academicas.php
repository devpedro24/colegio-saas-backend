<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('politicas_promocion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ano_lectivo_id')->unique()->constrained('anos_lectivos')->restrictOnDelete();
            $table->unsignedSmallInteger('max_reprobadas')->default(0);
            $table->json('materias_obligatorias')->nullable();
            $table->decimal('promedio_minimo', 18, 8)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('promociones_academicas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->foreignId('matricula_id')->unique()->constrained('matriculas')->restrictOnDelete();
            $table->string('propuesta', 20);
            $table->string('resultado', 20);
            $table->foreignId('grado_destino_id')->nullable()->constrained('grados')->restrictOnDelete();
            $table->json('insumos');
            $table->string('huella', 64);
            $table->text('motivo');
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->index(['ano_lectivo_id', 'resultado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promociones_academicas');
        Schema::dropIfExists('politicas_promocion');
    }
};
