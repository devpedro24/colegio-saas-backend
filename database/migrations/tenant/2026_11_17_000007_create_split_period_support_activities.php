<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matricula_actividades_apoyo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consolidacion_id')->constrained('matricula_consolidaciones_periodo')->restrictOnDelete();
            $t->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $t->foreignId('actividad_id')->constrained('actividades_evaluacion')->restrictOnDelete();
            $t->date('tramo_desde');
            $t->date('fecha_entrega');
            $t->text('motivo');
            $t->foreignId('asignado_por_id')->constrained('users')->restrictOnDelete();
            $t->string('responsable');
            $t->timestampTz('asignado_en');
            $t->unique(['matricula_id', 'actividad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_actividades_apoyo');
    }
};
