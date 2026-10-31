<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('politicas_indicadores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ano_lectivo_id')->unique()->constrained('anos_lectivos')->restrictOnDelete();
            $table->string('alcance', 12);
            $table->boolean('docente_puede_editar')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('politicas_indicadores');
    }
};
