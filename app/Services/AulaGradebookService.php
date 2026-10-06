<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaEntrega;
use App\Models\Academico\AulaIntento;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Periodo;
use App\Models\Academico\Matricula;
use App\Models\Academico\AnoLectivo;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Brick\Math\BigDecimal;

final class AulaGradebookService
{
    public function __construct(private readonly AulaAccess $access, private readonly GradebookService $book,
        private readonly FlexibleGradingService $sheets) {}

    /** Publication and grades stay separate; this is idempotent even on retries. */
    public function link(AulaRecurso $resource, User $actor): ?ActividadEvaluacion
    {
        if (! $resource->llevar_planilla || ! $resource->calificable) return null;
        $aula = $resource->seccion->aula;
        $this->access->manages($actor, $aula);
        $assignment = $this->access->assignment($aula);
        abort_unless($assignment, 422, 'Asigna un docente antes de vincular la planilla.');
        $this->book->authorizeAssignment($actor, $assignment, write: true);
        return DB::transaction(function () use ($resource, $actor, $assignment) {
            $resource = AulaRecurso::whereKey($resource->id)->lockForUpdate()->firstOrFail();
            if ($resource->actividad_id) return ActividadEvaluacion::findOrFail($resource->actividad_id);
            $period = Periodo::findOrFail($resource->seccion->periodo_id);
            if ($period->estado === Periodo::ESTADO_PLANIFICADO) return null;
            $this->book->writable($assignment, $period->id);
            $section = $this->sheets->section($assignment, $period, [
                'preinforme_token' => $resource->seccion->preinforme_id
                    ? OpaqueUrlToken::for('preinforme', $resource->seccion->preinforme_id) : null,
            ]);
            abort_if($section->modo === 'WEIGHTED_AVERAGE' && $resource->peso === null, 422,
                'Indica el porcentaje de la actividad antes de vincularla.');
            if ($section->modo === 'WEIGHTED_AVERAGE') {
                $used = ActividadEvaluacion::where('componente_id', $section->id)->pluck('peso')
                    ->reduce(fn (BigDecimal $sum, $peso) => $sum->plus((string) ($peso ?? 0)), BigDecimal::zero());
                abort_if(BigDecimal::of((string) $resource->peso)->isLessThanOrEqualTo('0')
                    || $used->plus((string) $resource->peso)->isGreaterThan('100'), 422,
                    'Los porcentajes de actividades vinculadas no pueden superar 100 %.');
            }
            abort_if(ActividadEvaluacion::where('componente_id', $section->id)->where('nombre', $resource->titulo)->exists(), 422,
                'Ya existe una actividad con ese nombre en la planilla.');
            $today = now(tenant()->zonaHorariaInstitucional())->toDateString();
            $date = min(max($today, $period->fecha_inicio->toDateString()), $period->fecha_fin->toDateString());
            $activity = ActividadEvaluacion::create(['componente_id' => $section->id, 'nombre' => $resource->titulo,
                'fecha' => $date, 'peso' => $section->modo === 'WEIGHTED_AVERAGE' ? $resource->peso : null, 'version' => 1]);
            $resource->update(['actividad_id' => $activity->id]);
            AuditLogger::tenant($actor, 'CREATE', 'actividad_evaluacion', (string) $activity->id, null,
                ['aula_recurso_id' => $resource->id, 'actividad' => $activity->toArray()]);
            $this->backfill($resource, $activity, $assignment, $actor);

            return $activity;
        });
    }

    private function backfill(AulaRecurso $resource, ActividadEvaluacion $activity,
        \App\Models\Academico\AsignacionDocente $assignment, User $actor): void
    {
        $items = $resource->tipo === 'tarea'
            ? AulaEntrega::where('recurso_id', $resource->id)->whereNotNull('nota')->get()
            : AulaIntento::where('recurso_id', $resource->id)->whereNotNull('nota')
                ->orderByDesc('numero')->get()->unique('matricula_id');
        if ($items->isEmpty()) return;
        $eligible = Matricula::where('grupo_id', $assignment->grupo_id)->where('ano_lectivo_id', $assignment->ano_lectivo_id)
            ->where('estado', 'activa')->whereIn('id', $items->pluck('matricula_id'))->pluck('id')->all();
        $existing = Calificacion::where('actividad_id', $activity->id)->pluck('matricula_id')->all();
        $scale = app(EscalaVisualService::class)->forGroup($resource->seccion->aula->grupo);
        $rows = [];
        foreach ($items as $item) {
            if (! in_array($item->matricula_id, $eligible, true) || in_array($item->matricula_id, $existing, true)) continue;
            $choice = $scale ? ($item->escala_opcion_id
                ? $scale->opciones->firstWhere('id', $item->escala_opcion_id)
                : app(EscalaVisualService::class)->nearestChoice((string) $item->nota, $scale->opciones)) : null;
            $rows[] = ['actividad_id' => $activity->id, 'matricula_id' => $item->matricula_id,
                'valor' => $choice ? null : (string) $item->nota,
                'escala_opcion_token' => $choice ? OpaqueUrlToken::for('escala-opcion', $choice->id) : null,
                'version' => 0, 'motivo' => 'Vinculación posterior de recurso de Aula.'];
        }
        if ($rows) $this->book->saveGrades($actor, $assignment, $resource->seccion->periodo_id, $rows);
    }

    public function transfer(AulaRecurso $resource, User $actor, int $enrollmentId, ?string $value,
        ?string $visualChoice = null, ?string $reason = null): void
    {
        app(AcademicPlanAccess::class)->requireAula();
        $activity = $resource->actividad_id ? ActividadEvaluacion::findOrFail($resource->actividad_id) : $this->link($resource, $actor);
        if (! $activity) return; // Período planificado: transferencia pendiente, sin nota oficial.
        $aula = $resource->seccion->aula;
        $assignment = $this->access->assignment($aula);
        abort_unless($assignment, 422, 'La asignación docente ya no existe.');
        abort_if($this->book->requiresReason($actor, $assignment) && mb_strlen(trim((string) $reason)) < 8,
            422, 'La edición delegada de una nota requiere un motivo de al menos 8 caracteres.');
        $grade = Calificacion::where('actividad_id', $activity->id)->where('matricula_id', $enrollmentId)->first();
        $this->book->saveGrades($actor, $assignment, $resource->seccion->periodo_id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollmentId,
            'valor' => $visualChoice ? null : $value, 'escala_opcion_token' => $visualChoice,
            'version' => $grade?->version ?? 0, 'observacion' => 'Calificación desde Aula.',
            'motivo' => $reason,
        ]]);
    }

    /** Only a first official grade may be written automatically; never overwrite a teacher's edit. */
    public function transferAutomatic(AulaRecurso $resource, User $student, int $enrollmentId, string $value): bool
    {
        app(AcademicPlanAccess::class)->requireAula();
        if (! $resource->llevar_planilla || ! $resource->actividad_id) return false;
        $aula = $resource->seccion->aula;
        $enrollment = Matricula::whereKey($enrollmentId)->where('estudiante_id', $student->id)
            ->where('grupo_id', $aula->grupo_id)->where('ano_lectivo_id', $aula->ano_lectivo_id)->where('estado', 'activa')->first();
        abort_unless($enrollment, 403);
        $assignment = $this->access->assignment($aula);
        abort_unless($assignment, 422, 'La asignación docente ya no existe.');
        return DB::transaction(function () use ($resource, $student, $enrollment, $assignment, $value, $aula) {
            $this->book->writable($assignment, $resource->seccion->periodo_id);
            $config = app(SieeConfiguration::class)->resolve(AnoLectivo::findOrFail($aula->ano_lectivo_id));
            abort_if(BigDecimal::of($value)->isLessThan((string) $config['valor_min'])
                || BigDecimal::of($value)->isGreaterThan((string) $config['valor_max']), 422);
            $scale = app(EscalaVisualService::class)->forGroup($aula->grupo);
            $choice = $scale ? app(EscalaVisualService::class)->nearestChoice($value, $scale->opciones) : null;
            $existing = Calificacion::where('actividad_id', $resource->actividad_id)
                ->where('matricula_id', $enrollment->id)->lockForUpdate()->first();
            if ($existing) return false;
            $grade = Calificacion::create(['actividad_id' => $resource->actividad_id, 'matricula_id' => $enrollment->id,
                'valor' => $choice ? $choice->valor_equivalente : $value, 'escala_opcion_id' => $choice?->id,
                'updated_by' => $student->id, 'version' => 1,
                'observacion' => 'Autocalificación objetiva del Aula.']);
            AuditLogger::tenant($student, 'CREATE', 'calificacion', (string) $grade->id, null, $grade->toArray(),
                'Transferencia automática desde cuestionario del Aula.');

            return true;
        });
    }
}
