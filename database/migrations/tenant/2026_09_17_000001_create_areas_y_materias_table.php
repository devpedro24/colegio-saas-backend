<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Plan de estudios del tenant: áreas y materias. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->text('descripcion')->nullable();
            $table->string('estado')->default('activo');
            $table->timestamps();
            $table->softDeletes();
            $table->unique('nombre');
        });

        Schema::create('materias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            $table->foreignId('nivel_id')->nullable()->constrained('niveles')->nullOnDelete();
            $table->string('nombre', 120);
            $table->string('codigo', 30)->nullable();
            $table->unsignedTinyInteger('intensidad_horaria');
            $table->string('estado')->default('activo');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['area_id', 'nombre']);
            $table->unique('codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materias');
        Schema::dropIfExists('areas');
    }
};
