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
        Schema::table('asignaciones_docentes', function (Blueprint $table): void {
            $table->foreignId('docente_id')->nullable()->change();
        });

        // Normaliza clases creadas antes de que el horario generara la asignación.
        $sessions = DB::table('sesiones_horario')->whereNull('deleted_at')->whereNotNull('grupo_id')->whereNotNull('materia_id')
            ->orderBy('id')->get()->groupBy(fn ($row) => $row->grupo_id.':'.$row->materia_id);
        foreach ($sessions as $pair) {
            $first = $pair->first();
            $yearId = DB::table('grupos')->where('id', $first->grupo_id)->value('ano_lectivo_id');
            if ($yearId === null) {
                throw new RuntimeException('Una clase antigua no tiene grupo o año lectivo válido. Corrige sus referencias antes de migrar.');
            }
            $assignment = DB::table('asignaciones_docentes')->where('ano_lectivo_id', $yearId)
                ->where('grupo_id', $first->grupo_id)->where('materia_id', $first->materia_id)
                ->whereNull('deleted_at')->first();
            $teacherId = $assignment?->docente_id;
            if ($assignment === null) {
                $teachers = $pair->pluck('docente_id')->filter()->unique()->values();
                if ($teachers->count() > 1) {
                    throw new RuntimeException('Hay clases antiguas con docentes distintos para el mismo grupo y materia. Unifica el docente antes de migrar.');
                }
                $teacherId = $teachers->first();
                $assignmentId = DB::table('asignaciones_docentes')->insertGetId([
                    'ano_lectivo_id' => $yearId, 'grupo_id' => $first->grupo_id,
                    'materia_id' => $first->materia_id, 'docente_id' => $teacherId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $assignmentId = $assignment->id;
            }
            DB::table('sesiones_horario')->whereIn('id', $pair->pluck('id'))->update([
                'asignacion_id' => $assignmentId, 'docente_id' => $teacherId, 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('asignaciones_docentes')->whereNull('docente_id')->exists()) {
            throw new RuntimeException('Existen asignaciones pendientes de docente. Asígnalas antes de revertir esta migración.');
        }
        Schema::table('asignaciones_docentes', function (Blueprint $table): void {
            $table->foreignId('docente_id')->nullable(false)->change();
        });
    }
};
