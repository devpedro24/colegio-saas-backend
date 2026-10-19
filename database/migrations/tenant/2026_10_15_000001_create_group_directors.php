<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directores_grupo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grupo_id')->unique()->constrained('grupos')->restrictOnDelete();
            $table->foreignId('docente_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actualizado_por')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('asignado_en')->nullable();
            $table->timestamps();
            $table->index('docente_id');
        });

        Schema::create('directores_grupo_historial', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grupo_id')->constrained('grupos')->restrictOnDelete();
            $table->foreignId('docente_anterior_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('docente_nuevo_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('motivo', 1000)->nullable();
            $table->timestamp('registrado_en');
            $table->index(['grupo_id', 'registrado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directores_grupo_historial');
        Schema::dropIfExists('directores_grupo');
    }
};
