<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sesiones_horario')) {
            return;
        }

        Schema::table('sesiones_horario', function (Blueprint $table): void {
            if (! Schema::hasColumn('sesiones_horario', 'grupo_id')) {
                $table->foreignId('grupo_id')->nullable()->constrained('grupos')->restrictOnDelete();
            }
            if (! Schema::hasColumn('sesiones_horario', 'materia_id')) {
                $table->foreignId('materia_id')->nullable()->constrained('materias')->restrictOnDelete();
            }
            if (! Schema::hasColumn('sesiones_horario', 'docente_id')) {
                $table->foreignId('docente_id')->nullable()->constrained('users')->restrictOnDelete();
            }
            $table->foreignId('asignacion_id')->nullable()->change();
        });

        // Preserve existing schedules, including soft-deleted entries and assignments.
        DB::table('sesiones_horario as s')
            ->join('asignaciones_docentes as a', 'a.id', '=', 's.asignacion_id')
            ->select('s.id', 'a.grupo_id', 'a.materia_id', 'a.docente_id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('sesiones_horario')->where('id', $row->id)->update([
                        'grupo_id' => $row->grupo_id,
                        'materia_id' => $row->materia_id,
                        'docente_id' => $row->docente_id,
                    ]);
                }
            }, 's.id', 'id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('sesiones_horario')) {
            return;
        }
        if (DB::table('sesiones_horario')->whereNull('asignacion_id')->exists()) {
            throw new RuntimeException('Hay clases independientes de asignaciones docentes. No se puede revertir sin reasignarlas.');
        }

        Schema::table('sesiones_horario', function (Blueprint $table): void {
            $table->foreignId('asignacion_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('grupo_id');
            $table->dropConstrainedForeignId('materia_id');
            $table->dropConstrainedForeignId('docente_id');
        });
    }
};
