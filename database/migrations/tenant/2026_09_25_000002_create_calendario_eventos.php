<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_eventos', function (Blueprint $table) {
            $table->id();
            $table->boolean('docentes_cualquier_grupo')->default(false);
            $table->timestamps();
        });
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 180);
            $table->text('descripcion');
            $table->date('fecha')->index();
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fin')->nullable();
            $table->string('categoria', 30)->default('actividad');
            $table->boolean('institucional')->default(false);
            $table->foreignId('materia_id')->nullable()->constrained('materias')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('evento_grupo', function (Blueprint $table) {
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();
            $table->foreignId('grupo_id')->constrained('grupos')->restrictOnDelete();
            $table->primary(['evento_id', 'grupo_id']);
        });
        Schema::create('evento_archivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('eventos')->cascadeOnDelete();
            $table->unsignedBigInteger('stored_file_id'); // El archivo vive en la BD central.
            $table->unique(['evento_id', 'stored_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_archivos');
        Schema::dropIfExists('evento_grupo');
        Schema::dropIfExists('eventos');
        Schema::dropIfExists('configuracion_eventos');
    }
};
