<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('aula_preguntas', function (Blueprint $table): void {
            $table->json('puntajes_opciones')->nullable();
            $table->json('rubrica')->nullable();
        });
        Schema::table('aula_intentos', fn (Blueprint $table) => $table->json('revision_preguntas')->nullable());
        Schema::create('aula_pregunta_medios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pregunta_id')->constrained('aula_preguntas')->cascadeOnDelete();
            $table->unsignedInteger('opcion_indice')->nullable();
            $table->string('archivo_token', 24);
            $table->string('nombre', 255);
            $table->string('mime', 100);
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['pregunta_id', 'opcion_indice']);
        });
        Schema::create('aula_respuesta_medios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intento_id')->constrained('aula_intentos')->restrictOnDelete();
            $table->foreignId('pregunta_id')->constrained('aula_preguntas')->restrictOnDelete();
            $table->string('archivo_token', 24);
            $table->string('nombre', 255);
            $table->string('mime', 100);
            $table->timestamps();
            $table->unique(['intento_id', 'pregunta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aula_respuesta_medios');
        Schema::dropIfExists('aula_pregunta_medios');
        Schema::table('aula_intentos', fn (Blueprint $table) => $table->dropColumn('revision_preguntas'));
        Schema::table('aula_preguntas', fn (Blueprint $table) => $table->dropColumn(['puntajes_opciones', 'rubrica']));
    }
};
