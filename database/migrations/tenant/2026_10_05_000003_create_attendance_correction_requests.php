<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $table->foreignId('asignacion_id')->constrained('asignaciones_docentes')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('solicitado_por_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('remitido_por_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('resuelto_por_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('origen', 16); // estudiante o docente
            $table->string('estado', 32); // revision_docente, pendiente_aprobacion, aprobada, rechazada
            $table->text('motivo');
            $table->text('respuesta_docente')->nullable();
            $table->text('respuesta_aprobador')->nullable();
            $table->timestamp('remitido_at')->nullable();
            $table->timestamp('resuelto_at')->nullable();
            $table->timestamps();
            $table->index(['estado', 'created_at']);
            $table->index(['asignacion_id', 'estado']);
            $table->index(['matricula_id', 'estado']);
        });

        Schema::create('asistencia_solicitud_marcas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('asistencia_solicitudes')->restrictOnDelete();
            $table->foreignId('asistencia_marca_id')->constrained('asistencia_marcas')->restrictOnDelete();
            $table->string('estado_anterior', 16);
            $table->timestamps();
            $table->unique(['solicitud_id', 'asistencia_marca_id']);
            $table->index('asistencia_marca_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_solicitud_marcas');
        Schema::dropIfExists('asistencia_solicitudes');
    }
};
