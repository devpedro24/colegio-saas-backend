<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matricula_certificados_pendientes', function (Blueprint $t) {
            $t->id();
            $t->char('token', 24)->unique();
            $t->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $t->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $t->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $t->date('fecha_ingreso');
            $t->date('fecha_seguimiento');
            $t->foreignId('responsable_id')->constrained('users')->restrictOnDelete();
            $t->string('responsable');
            $t->foreignId('registrador_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('registrado_en');
            $t->foreignId('resultado_id')->nullable()->unique()->constrained('matricula_definitivas_externas')->restrictOnDelete();
            $t->unique(['matricula_id','materia_id','periodo_id'], 'certificado_pendiente_destino_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_certificados_pendientes');
    }
};
