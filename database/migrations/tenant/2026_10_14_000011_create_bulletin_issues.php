<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boletin_emisiones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->string('contexto', 40);
            $table->foreignId('periodo_id')->nullable()->constrained('periodos')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('estado', 20); // preparado, aprobado, publicado, sustituido, descartado
            $table->foreignId('plantilla_id')->nullable()->constrained('boletin_plantillas')->restrictOnDelete();
            $table->unsignedInteger('plantilla_revision')->nullable();
            $table->json('snapshot');
            $table->string('huella_insumos', 64);
            $table->string('pdf_path')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['matricula_id', 'contexto', 'revision']);
            $table->index(['matricula_id', 'contexto', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boletin_emisiones');
    }
};
