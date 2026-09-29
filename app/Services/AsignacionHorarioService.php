<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\Academico\SesionHorario;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Una asignación por año, grupo y materia; todas sus clases comparten docente. */
final class AsignacionHorarioService
{
    public function guardar(Grupo $grupo, Materia $materia, ?int $docenteId, User $actor): AsignacionDocente
    {
        return DB::transaction(function () use ($grupo, $materia, $docenteId, $actor): AsignacionDocente {
            $asignacion = AsignacionDocente::query()
                ->where('ano_lectivo_id', $grupo->ano_lectivo_id)
                ->where('grupo_id', $grupo->id)
                ->where('materia_id', $materia->id)
                ->lockForUpdate()->first();

            if ($docenteId !== null) {
                $docente = User::findOrFail($docenteId);
                if ($docente->status !== 'active' || ! $docente->hasRole('docente')) {
                    throw ValidationException::withMessages(['docente_id' => 'Selecciona un docente activo del colegio.']);
                }
            }

            $clases = SesionHorario::with('bloque')
                ->where('grupo_id', $grupo->id)
                ->where('materia_id', $materia->id)->get();
            if ($docenteId !== null && $clases->isNotEmpty()) {
                $otras = SesionHorario::with('bloque')
                    ->where('docente_id', $docenteId)
                    ->whereNotIn('id', $clases->pluck('id'))
                    ->whereHas('grupo', fn ($query) => $query->where('ano_lectivo_id', $grupo->ano_lectivo_id))
                    ->get();
                foreach ($clases as $clase) {
                    $inicio = substr((string) ($clase->bloque?->hora_inicio ?? $clase->hora_inicio), 0, 5);
                    $fin = substr((string) ($clase->bloque?->hora_fin ?? $clase->hora_fin), 0, 5);
                    foreach ($otras as $otra) {
                        $otroInicio = substr((string) ($otra->bloque?->hora_inicio ?? $otra->hora_inicio), 0, 5);
                        $otroFin = substr((string) ($otra->bloque?->hora_fin ?? $otra->hora_fin), 0, 5);
                        if ($clase->dia === $otra->dia && $inicio < $otroFin && $fin > $otroInicio) {
                            throw ValidationException::withMessages([
                                'docente_id' => 'El docente ya tiene otra clase durante una de las franjas de esta materia y grupo.',
                            ]);
                        }
                    }
                }
            }

            if ($asignacion === null) {
                $asignacion = AsignacionDocente::create([
                    'ano_lectivo_id' => $grupo->ano_lectivo_id,
                    'grupo_id' => $grupo->id,
                    'materia_id' => $materia->id,
                    'docente_id' => $docenteId,
                ]);
                AuditLogger::tenant($actor, 'CREATE', 'asignacion_docente', (string) $asignacion->id, null, $asignacion->toArray());
            } elseif ($asignacion->docente_id !== $docenteId) {
                $anterior = $asignacion->toArray();
                $asignacion->update(['docente_id' => $docenteId]);
                AuditLogger::tenant($actor, 'UPDATE', 'asignacion_docente', (string) $asignacion->id, $anterior, $asignacion->toArray());
            }

            foreach ($clases as $clase) {
                if ($clase->asignacion_id === $asignacion->id && $clase->docente_id === $docenteId) {
                    continue;
                }
                $anterior = $clase->toArray();
                $clase->update(['asignacion_id' => $asignacion->id, 'docente_id' => $docenteId]);
                AuditLogger::tenant($actor, 'UPDATE', 'sesion_horario', (string) $clase->id, $anterior, $clase->toArray());
            }

            return $asignacion;
        });
    }
}
