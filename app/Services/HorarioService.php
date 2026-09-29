<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\Academico\SesionHorario;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HorarioService
{
    private readonly AsignacionHorarioService $asignaciones;

    public function __construct(?AsignacionHorarioService $asignaciones = null)
    {
        $this->asignaciones = $asignaciones ?? new AsignacionHorarioService;
    }

    public function guardar(array $data, User $actor, ?SesionHorario $session = null): SesionHorario
    {
        return DB::transaction(function () use ($data, $actor, $session) {
            // Clients may provide the assignment, but a class can create it automatically.
            $assignment = isset($data['asignacion_id']) ? AsignacionDocente::findOrFail($data['asignacion_id']) : null;
            $groupId = $data['grupo_id'] ?? $assignment?->grupo_id ?? $session?->grupo_id;
            $subjectId = $data['materia_id'] ?? $assignment?->materia_id ?? $session?->materia_id;
            if (! $groupId || ! $subjectId) {
                throw ValidationException::withMessages(['grupo_id' => 'Selecciona el grupo y la asignatura de la clase.']);
            }
            $group = Grupo::with(['jornada', 'grado'])->findOrFail($groupId);
            $subject = Materia::findOrFail($subjectId);
            $year = AnoLectivo::lockForUpdate()->findOrFail($group->ano_lectivo_id);
            abort_if($year->estaCerrado(), 422, 'El año lectivo está cerrado.');
            if ($session) {
                AcademicYearSelection::assertSame($session->ano_lectivo_id, $year);
            }
            AcademicYearSelection::assertSame($subject->ano_lectivo_id, $year);
            AcademicYearSelection::assertSame($group->grado?->ano_lectivo_id, $year);
            AcademicYearSelection::assertSame($group->jornada?->ano_lectivo_id, $year);
            abort_unless($group->estaActivo() && $subject->estado === 'activo', 422, 'El grupo y la asignatura deben estar activos.');
            abort_unless($subject->nivel_id === null || $subject->nivel_id == $group->grado?->nivel_id, 422, 'La asignatura no corresponde al nivel del grupo.');
            if ($assignment) {
                abort_unless($assignment->ano_lectivo_id == $year->id && $assignment->grupo_id == $group->id && $assignment->materia_id == $subject->id, 422, 'La asignación no corresponde a la clase.');
            } else {
                $assignment = AsignacionDocente::query()
                    ->where('ano_lectivo_id', $year->id)
                    ->where('grupo_id', $group->id)
                    ->where('materia_id', $subject->id)->first();
            }

            $teacherId = $session === null && empty($data['docente_id'])
                ? $assignment?->docente_id
                : (array_key_exists('docente_id', $data) ? $data['docente_id'] : ($assignment?->docente_id ?? $session?->docente_id));
            if ($teacherId !== null) {
                $teacher = User::findOrFail($teacherId);
                abort_unless($teacher->status === 'active' && $teacher->hasRole('docente'), 422, 'Selecciona un docente activo del colegio.');
            }

            $blockId = $data['bloque_horario_id'] ?? null;
            $hasStart = isset($data['hora_inicio']);
            $hasEnd = isset($data['hora_fin']);
            if (($blockId !== null && ($hasStart || $hasEnd)) || ($blockId === null && (! $hasStart || ! $hasEnd))) {
                throw ValidationException::withMessages(['horario' => 'Selecciona un bloque o indica la hora de inicio y fin de la clase.']);
            }
            $block = $blockId !== null ? BloqueHorario::findOrFail($blockId) : null;
            if ($block) {
                AcademicYearSelection::assertSame($block->ano_lectivo_id, $year);
                abort_unless($block->estaActivo() && ! $block->es_descanso && $group->jornada_id === $block->jornada_id, 422, 'Selecciona un bloque de clase activo de la jornada del grupo.');
            }
            $inicio = $block?->hora_inicio ?? $data['hora_inicio'];
            $fin = $block?->hora_fin ?? $data['hora_fin'];
            if ($this->minutes($inicio) >= $this->minutes($fin)) {
                throw ValidationException::withMessages(['hora_fin' => 'La hora de fin debe ser posterior a la hora de inicio.']);
            }
            $jornada = $group->jornada;
            abort_unless($jornada && $jornada->estaActiva(), 422, 'El grupo necesita una jornada activa para programar clases.');
            abort_unless(($jornada->hora_inicio === null || $this->minutes($inicio) >= $this->minutes($jornada->hora_inicio))
                && ($jornada->hora_fin === null || $this->minutes($fin) <= $this->minutes($jornada->hora_fin)), 422, 'La clase debe estar dentro del horario de la jornada del grupo.');

            $spaceId = $data['espacio_fisico_id'] ?? null;
            if ($spaceId !== null) {
                $space = EspacioFisico::findOrFail($spaceId);
                AcademicYearSelection::assertSame($space->ano_lectivo_id, $year);
                abort_unless($space->estado === EspacioFisico::ESTADO_DISPONIBLE && $space->sede_id === $group->sede_id, 422, 'El espacio debe estar disponible y pertenecer a la sede del grupo.');
            }

            // Check real intervals, whether or not either class uses a block.
            $existing = SesionHorario::with(['bloque', 'materia:id,nombre', 'grupo.grado:id,nombre'])
                ->where('ano_lectivo_id', $year->id)
                ->when($session, fn ($q) => $q->whereKeyNot($session->id))->get();
            $weekly = $this->minutes($fin) - $this->minutes($inicio);
            foreach ($existing as $entry) {
                $entryStart = $entry->bloque?->hora_inicio ?? $entry->hora_inicio;
                $entryEnd = $entry->bloque?->hora_fin ?? $entry->hora_fin;
                abort_unless($entryStart !== null && $entryEnd !== null, 422, 'Existe una clase sin horario válido. Corrígela antes de continuar.');
                if ($entry->grupo_id == $group->id && $entry->materia_id == $subject->id) {
                    $weekly += $this->minutes($entryEnd) - $this->minutes($entryStart);
                }
                if ($entry->dia !== $data['dia'] || $this->minutes($inicio) >= $this->minutes($entryEnd) || $this->minutes($fin) <= $this->minutes($entryStart)) {
                    continue;
                }
                $conflicts = [];
                if ($teacherId !== null && $entry->docente_id == $teacherId) {
                    $conflicts[] = 'docente';
                }
                if ($entry->grupo_id == $group->id) {
                    $conflicts[] = 'grupo';
                }
                if ($spaceId !== null && $entry->espacio_fisico_id == $spaceId) {
                    $conflicts[] = 'espacio';
                }
                if ($conflicts) {
                    $grupoExistente = ($entry->grupo?->grado?->nombre ?? 'Grado no disponible').' / ('.($entry->grupo?->nombre ?? 'Grupo no disponible').')';
                    $claseExistente = ($entry->materia?->nombre ?? 'sin asignatura').' de '.$grupoExistente;
                    $horasExistentes = substr($entryStart, 0, 5).' a '.substr($entryEnd, 0, 5);
                    throw ValidationException::withMessages([
                        'horario' => 'Hay un cruce de '.implode(', ', $conflicts).' con '.$claseExistente.' el '.$entry->dia.' de '.$horasExistentes.'.',
                    ]);
                }
            }
            abort_if($weekly > (int) $subject->intensidad_horaria * 60, 422, 'Se excede la intensidad horaria semanal de la asignatura.');

            $previous = $session?->toArray();
            $oldAssignment = $session?->asignacion_id ? AsignacionDocente::find($session->asignacion_id) : null;
            $changedPair = $session && ($session->grupo_id != $group->id || $session->materia_id != $subject->id);
            $oldIsLastClass = $changedPair && $oldAssignment && ! SesionHorario::where('asignacion_id', $oldAssignment->id)
                ->whereKeyNot($session->id)->exists();
            if ($oldIsLastClass && ComponenteEvaluacion::where('asignacion_id', $oldAssignment->id)->exists()) {
                throw ValidationException::withMessages(['grupo_id' => 'La asignación anterior tiene evaluaciones asociadas. No puedes trasladar su última clase a otra materia o grupo.']);
            }
            $targetAssignment = $changedPair ? AsignacionDocente::where('ano_lectivo_id', $year->id)
                ->where('grupo_id', $group->id)->where('materia_id', $subject->id)->first() : null;
            if ($oldIsLastClass && ! $targetAssignment) {
                $beforeAssignment = $oldAssignment->toArray();
                $oldAssignment->update(['grupo_id' => $group->id, 'materia_id' => $subject->id]);
                AuditLogger::tenant($actor, 'UPDATE', 'asignacion_docente', (string) $oldAssignment->id, $beforeAssignment, $oldAssignment->toArray());
            }
            // Exclude this moving class from the target teacher's conflict check.
            if ($changedPair) {
                $session->update(['grupo_id' => $group->id, 'materia_id' => $subject->id]);
            }
            $assignment = $this->asignaciones->guardar($group, $subject, $teacherId, $actor);
            $session ??= new SesionHorario;
            $session->fill([
                'ano_lectivo_id' => $year->id,
                'asignacion_id' => $assignment->id,
                'grupo_id' => $group->id,
                'materia_id' => $subject->id,
                'docente_id' => $teacherId,
                'dia' => $data['dia'],
                'bloque_horario_id' => $block?->id,
                'hora_inicio' => $block ? null : $inicio,
                'hora_fin' => $block ? null : $fin,
                'espacio_fisico_id' => $spaceId,
            ])->save();
            AuditLogger::tenant($actor, $previous ? 'UPDATE' : 'CREATE', 'sesion_horario', (string) $session->id, $previous, $session->toArray());
            if ($oldIsLastClass && $targetAssignment && $oldAssignment->id !== $assignment->id) {
                AuditLogger::tenant($actor, 'DELETE', 'asignacion_docente', (string) $oldAssignment->id, $oldAssignment->toArray());
                $oldAssignment->delete();
            }

            return $session->load(['grupo.grado', 'materia', 'docente:id,name', 'bloque', 'espacio']);
        });
    }

    private function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
