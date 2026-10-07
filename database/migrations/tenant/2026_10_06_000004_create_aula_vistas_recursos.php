<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aula_vistas_recursos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recurso_id')->constrained('aula_recursos')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->dateTime('abierto_at');
            $table->unique(['recurso_id', 'matricula_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aula_vistas_recursos');
    }
};
