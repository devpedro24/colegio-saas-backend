<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matricula_consolidaciones_periodo', function (Blueprint $t) {
            $t->id();
            $t->char('token', 24)->unique();
            $t->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $t->foreignId('materia_id')->constrained('materias')->restrictOnDelete();
            $t->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $t->foreignId('movimiento_id')->constrained('matricula_movimientos')->restrictOnDelete();
            $t->jsonb('tramos');
            $t->jsonb('decision')->nullable();
            $t->timestampTz('sustituido_en')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX consolidacion_vigente_materia_periodo ON matricula_consolidaciones_periodo (matricula_id, materia_id, periodo_id) WHERE sustituido_en IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_consolidaciones_periodo');
    }
};
