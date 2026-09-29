<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copias_configuracion_anual', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ano_lectivo_id')->unique()->constrained('anos_lectivos')->cascadeOnDelete();
            $table->foreignId('origen_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->json('opciones');
            $table->string('huella', 64);
            $table->boolean('reemplazable')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copias_configuracion_anual');
    }
};
