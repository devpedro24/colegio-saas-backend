<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correo_revisiones', function (Blueprint $table) {
            $table->id();
            $table->string('modulo', 12);
            $table->unsignedBigInteger('publicacion_id');
            $table->string('selector', 24);
            $table->string('decision', 12);
            $table->unsignedInteger('intento');
            $table->unsignedBigInteger('responsable_id');
            $table->string('responsable');
            $table->string('destinatario');
            $table->text('motivo');
            $table->timestampTz('fecha');
            $table->unique(['modulo', 'publicacion_id', 'selector', 'decision'], 'correo_decision_unica');
            $table->index(['modulo', 'publicacion_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correo_revisiones');
    }
};
