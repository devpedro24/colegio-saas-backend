<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anos_lectivos', function (Blueprint $table) {
            $table->json('siee')->nullable();
            $table->unsignedInteger('siee_version')->default(1);
        });
        Schema::table('materias', fn (Blueprint $table) => $table->foreignId('area_id')->nullable()->change());
        Schema::create('materias_curriculares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->foreignId('grado_id')->constrained('grados')->restrictOnDelete();
            $table->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->restrictOnDelete();
            $table->decimal('peso_area', 7, 4)->nullable();
            $table->timestamps();
            $table->unique(['ano_lectivo_id', 'grado_id', 'materia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materias_curriculares');
        Schema::table('anos_lectivos', fn (Blueprint $table) => $table->dropColumn(['siee', 'siee_version']));
        // No forzar NOT NULL: las asignaturas sin área son datos válidos.
    }
};
