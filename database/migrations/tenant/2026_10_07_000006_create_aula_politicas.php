<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('aula_politicas', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('permitir_edicion_periodos_cerrados')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aula_politicas');
    }
};
