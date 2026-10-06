<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_politicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ano_lectivo_id')->unique()->constrained('anos_lectivos')->restrictOnDelete();
            $table->unsignedSmallInteger('max_faltas')->nullable();
            $table->decimal('max_porcentaje', 5, 2)->nullable();
            $table->string('combinacion', 16)->default('cualquiera');
            $table->string('ambito', 16)->default('periodo');
            $table->unsignedSmallInteger('tardes_por_falta')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_politicas');
    }
};
