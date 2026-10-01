<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\RecuperacionAcademica;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class GradebookService
{
    public function manages(User $user): bool
    {
        return $user->can('notas.ver_consolidado_todos') || $user->can('notas.editar_no_dicta');
    }

    public function canManageEnrollment(User $user): bool
    {
        return $user->can('academico.matriculas.gestionar');
    }

    public function requiresReason(User $actor, AsignacionDocente $assignment): bool
    {
        // El docente asignado registra notas ordinarias sin justificar cada lote.
        // La edición delegada de una planilla ajena sí pide un motivo auditado.
        return $assignment->docente_id !== $actor->id;
    }

    public function provisionalFromResult(array $result, array $config): ?string
    {
        if (($result['estado'] ?? null) === 'calculado') return null;
        return isset($result['trace']) ? \App\Support\AcademicDecimal::normalize(
            app(GradeCalculationService::class)->provisional($result['trace'], $config)) : null;
    }

    public function authorizeAssignment(User $user, AsignacionDocente $assignment, bool $write = false): void
    {
        $assigned = $assignment->docente_id == $user->id
            && $user->can('notas.registrar_materia_asignada');
        $allowed = $write
            ? $user->can('notas.editar_no_dicta') || $assigned
            : $this->manages($user) || $assigned;
        abort_unless($allowed, 403);
    }

    /** Dentro de una transacción: todos los escritores toman los mismos locks. */
    public function writable(AsignacionDocente $assignment, int $periodId): Periodo
    {
        $year = AnoLectivo::lockForUpdate()->findOrFail($assignment->ano_lectivo_id);
        $period = Periodo::where('ano_lectivo_id', $year->id)->lockForUpdate()->findOrFail($periodId);
        abort_unless($year->estado === 'en_curso' && $period->estado === 'abierto', 422, 'El año debe estar en curso y el período abierto para registrar o modificar notas.');

        return $period;
    }

    public function saveGrades(User $actor, AsignacionDocente $assignment, int $periodId, array $rows): void
    {
        $this->authorizeAssignment($actor, $assignment, write: true);
        DB::transaction(function () use ($actor, $assignment, $periodId, $rows) {
            $period = $this->writable($assignment, $periodId);
            if ($period->configuracion_notas['usar_preinformes'] ?? false) app(AcademicPlanAccess::class)->requirePreinformes();
            $config = app(SieeConfiguration::class)->resolve(AnoLectivo::findOrFail($assignment->ano_lectivo_id));
            $activities = DB::table('actividades_evaluacion as a')->join('componentes_evaluacion as c', 'c.id', '=', 'a.componente_id')
                ->where('c.asignacion_id', $assignment->id)->where('c.periodo_id', $periodId)->pluck('a.id')->all();
            $enrollments = Matricula::where('grupo_id', $assignment->grupo_id)->where('estado', 'activa')->pluck('id')->all();
            $seen = [];
            foreach ($rows as $row) {
                $key = $row['actividad_id'].':'.$row['matricula_id'];
                abort_if(isset($seen[$key]), 422, 'La misma nota aparece más de una vez.');
                $seen[$key] = true;
                abort_unless(in_array($row['actividad_id'], $activities) && in_array($row['matricula_id'], $enrollments), 422, 'La actividad y el estudiante deben pertenecer a esta asignación.');
                if ($row['valor'] !== null) {
                    $value = BigDecimal::of((string) $row['valor']);
                    abort_if($value->isLessThan($config['valor_min']) || $value->isGreaterThan($config['valor_max']), 422, 'La nota está fuera de la escala configurada.');
                }
                $grade = Calificacion::where('actividad_id', $row['actividad_id'])->where('matricula_id', $row['matricula_id'])->first();
                abort_unless(($grade?->version ?? 0) === (int) $row['version'], 409, 'Otra persona modificó una nota. Recarga la planilla antes de guardar.');
                $previous = $grade?->toArray();
                $grade ??= new Calificacion(['actividad_id' => $row['actividad_id'], 'matricula_id' => $row['matricula_id']]);
                $grade->fill(['valor' => $row['valor'], 'observacion' => $row['observacion'] ?? null, 'updated_by' => $actor->id, 'version' => ($grade->version ?? 0) + 1])->save();
                AuditLogger::tenant($actor, $previous ? 'UPDATE' : 'CREATE', 'calificacion', (string) $grade->id, $previous, $grade->toArray(), $row['motivo'] ?? null);
            }
        });
    }

    /** Ninguna nota pendiente se convierte en cero. Los derivados se calculan bajo demanda. */
    public function subjectResult(AsignacionDocente $assignment, Matricula $enrollment, Periodo $period, array $config): array
    {
        $components = ComponenteEvaluacion::with('actividades')->where('asignacion_id', $assignment->id)->where('periodo_id', $period->id)->get();
        $grades = Calificacion::where('matricula_id', $enrollment->id)->whereIn('actividad_id', $components->flatMap(fn ($c) => $c->actividades->pluck('id')))->get()->keyBy('actividad_id');

        return $this->resultFromLoaded($components, $grades, $this->periodConfig($config, $period));
    }

    public function periodConfig(array $config, Periodo $period): array
    {
        $config['_periodo'] = $period->configuracion_notas;
        if ($period->configuracion_notas['usar_preinformes'] ?? false) {
            $config['_preinformes'] = \App\Models\Academico\Preinforme::where('periodo_id', $period->id)->orderBy('orden')->get();
        }
        return $config;
    }

    /** Pure calculation over already authorized, scoped data. No per-student queries. */
    public function resultFromLoaded(Collection $components, Collection $grades, array $config): array
    {
        if ($config['_periodo']['usar_preinformes'] ?? false) {
            $inputs = $config['_preinformes']->map(function ($pre) use ($components, $grades) {
                $component = $components->firstWhere('preinforme_id', $pre->id);
                return ['reference' => 'preinforme:'.$pre->id, 'label' => $pre->nombre, 'weight' => $pre->peso,
                    'node' => ['mode' => $component?->modo ?? 'SIMPLE_AVERAGE', 'inputs' => $component
                        ? $component->actividades->map(fn ($activity) => ['reference' => 'actividad:'.$activity->id,
                            'weight' => $activity->peso, 'value' => $grades->get($activity->id)?->valor])->all() : []]];
            })->all();
            return $this->calculate(['mode' => $config['_periodo']['modo'], 'inputs' => $inputs], $config);
        }
        if ($components->count() === 1 && $components->first()->es_directo) {
            $component = $components->first();
            return $this->calculate(['mode' => $component->modo, 'inputs' => $component->actividades->map(fn ($activity) => [
                'reference' => 'actividad:'.$activity->id, 'label' => $activity->nombre,
                'weight' => $activity->peso, 'value' => $grades->get($activity->id)?->valor,
            ])->all()], $config);
        }
        $inputs = $components->map(fn ($component) => [
            'reference' => 'componente:'.$component->id, 'label' => $component->nombre, 'weight' => $component->peso,
            'node' => ['mode' => $component->modo, 'inputs' => $component->actividades->map(fn ($activity) => [
                'reference' => 'actividad:'.$activity->id, 'label' => $activity->nombre, 'weight' => $activity->peso,
                'value' => $grades->get($activity->id)?->valor,
            ])->all()],
        ])->all();

        return $this->calculate(['mode' => $config['modo_asignatura'], 'inputs' => $inputs], $config);
    }

    public function calculate(array $node, array $config): array
    {
        try {
            return ['estado' => 'calculado', ...app(GradeCalculationService::class)->result($node, $config)];
        } catch (ValidationException $error) {
            return ['estado' => 'pendiente', 'motivo' => collect($error->errors())->flatten()->first(), 'trace' => $node];
        }
    }

    public function report(Matricula $enrollment, bool $applyAnnualRecoveries = true): array
    {
        $year = AnoLectivo::findOrFail($enrollment->ano_lectivo_id);
        $config = app(SieeConfiguration::class)->resolve($year);
        $periods = Periodo::where('ano_lectivo_id', $year->id)->orderBy('orden')->get();
        $subjects = AsignacionDocente::with('materia.area')->where('grupo_id', $enrollment->grupo_id)
            ->where('ano_lectivo_id', $year->id)->get();
        $curriculum = DB::table('materias_curriculares')->where('ano_lectivo_id', $year->id)->where('grado_id', $enrollment->grupo->grado_id)->get()->keyBy('materia_id');
        $components = ComponenteEvaluacion::with('actividades')->whereIn('asignacion_id', $subjects->modelKeys())
            ->whereIn('periodo_id', $periods->modelKeys())->get();
        $grades = Calificacion::where('matricula_id', $enrollment->id)
            ->whereIn('actividad_id', $components->flatMap(fn ($c) => $c->actividades->modelKeys()))->get()->keyBy('actividad_id');
        $componentsByPeriod = $components->groupBy(fn ($c) => $c->asignacion_id.':'.$c->periodo_id);
        $recoveries = RecuperacionAcademica::where('matricula_id', $enrollment->id)
            ->where('ano_lectivo_id', $year->id)->whereIn('asignacion_id', $subjects->modelKeys())
            ->whereIn('estado', ['aprobada', 'no_aprobada'])->get()
            ->keyBy(fn ($item) => $item->asignacion_id.':'.$item->alcance);
        $periodConfigs = $periods->mapWithKeys(fn ($period) => [$period->id => $this->periodConfig($config, $period)]);
        $rows = $subjects->map(function ($assignment) use ($periods, $periodConfigs, $config, $curriculum, $year, $componentsByPeriod, $grades, $recoveries, $applyAnnualRecoveries) {
            $results = $periods->map(function ($period) use ($assignment, $componentsByPeriod, $grades, $periodConfigs, $config, $recoveries) {
                $original = $this->resultFromLoaded($componentsByPeriod->get($assignment->id.':'.$period->id, collect()), $grades, $periodConfigs[$period->id]);
                $recovery = $recoveries->get($assignment->id.':periodo:'.$period->id);

                return ['periodo_id' => $period->id, ...$this->applyRecovery($original, $recovery, $config)];
            })->all();
            $annual = $this->annual($results, $periods, $year, $config);
            if ($applyAnnualRecoveries) {
                $annual = $this->applyRecovery($annual, $recoveries->get($assignment->id.':anual'), $config);
            }
            $entry = $curriculum->get($assignment->materia_id);

            return ['materia_id' => $assignment->materia_id, 'nombre' => $assignment->materia->nombre,
                'area_id' => $entry ? $entry->area_id : $assignment->materia->area_id, 'peso_area' => $entry?->peso_area,
                'periodos' => $results, 'anual' => $annual];
        });
        // El currículo es obligatorio aunque falte asignar docente. No promediar
        // silenciosamente solo las materias que tienen asignación o calificaciones.
        $missingSubjects = $curriculum->except($subjects->pluck('materia_id')->all());
        $subjectNames = DB::table('materias')->whereIn('id', $missingSubjects->keys())->pluck('nombre', 'id');
        foreach ($missingSubjects as $entry) {
            $pending = ['estado' => 'pendiente', 'motivo' => 'La asignatura aún no tiene asignación para este grupo.'];
            $rows->push([
                'materia_id' => $entry->materia_id,
                'nombre' => $subjectNames->get($entry->materia_id),
                'area_id' => $entry->area_id, 'peso_area' => $entry->peso_area,
                'periodos' => $periods->map(fn ($period) => ['periodo_id' => $period->id, ...$pending])->all(),
                'anual' => $pending,
            ]);
        }
        $areas = [];
        if ($config['usar_areas']) {
            $areaNames = DB::table('areas')->whereIn('id', $rows->pluck('area_id')->filter()->unique())->pluck('nombre', 'id');
            foreach ($rows->whereNotNull('area_id')->groupBy('area_id') as $areaId => $members) {
                $areaResults = $periods->map(function ($period) use ($members, $config) {
                    $inputs = $members->map(function ($member) use ($period) {
                        $result = collect($member['periodos'])->firstWhere('periodo_id', $period->id);

                        return ['reference' => 'materia:'.$member['materia_id'], 'weight' => $member['peso_area'], 'value' => $result['exact_value'] ?? null];
                    })->values()->all();

                    return ['periodo_id' => $period->id, ...$this->calculate(['mode' => $config['modo_area'], 'inputs' => $inputs], $config)];
                })->all();
                $areas[] = ['area_id' => $areaId, 'nombre' => $areaNames->get($areaId), 'periodos' => $areaResults, 'anual' => $this->annual($areaResults, $periods, $year, $config)];
            }
        }

        return ['tipo' => 'VISTA_PREVIA', 'generado_en' => now()->toIso8601String(), 'institucion' => tenant('name'),
            'estudiante' => $enrollment->estudiante->only('id', 'name'), 'grupo' => $enrollment->grupo->nombre, 'grado' => $enrollment->grupo->grado->nombre,
            'ano' => $year->nombre, 'configuracion' => $config, 'periodos' => $periods->map->only(['id', 'nombre', 'peso', 'estado']),
            'periodo_sumatorio' => $year->periodo_sumatorio ? ['orden' => $year->num_periodos + 1, 'nombre' => 'P'.($year->num_periodos + 1), 'modo' => $config['modo_anual']] : null,
            'asignaturas' => $rows->values(), 'areas' => $areas,
            'advertencias' => $curriculum->keys()->diff($subjects->pluck('materia_id'))->isNotEmpty() ? ['Hay asignaturas del currículo sin asignación para este grupo; este informe no está completo.'] : [],
        ];
    }

    private function annual(array $results, $periods, AnoLectivo $year, array $config): array
    {
        if ($periods->count() !== $year->num_periodos) {
            return ['estado' => 'pendiente', 'motivo' => 'Faltan períodos por configurar.'];
        }
        $inputs = $periods->map(fn ($period, $index) => ['reference' => 'periodo:'.$period->id, 'weight' => $period->peso, 'value' => $results[$index]['exact_value'] ?? null])->all();

        return $this->calculate(['mode' => $config['modo_anual'], 'inputs' => $inputs], $config);
    }

    private function applyRecovery(array $original, ?RecuperacionAcademica $recovery, array $config): array
    {
        if (! $recovery) {
            return $original;
        }
        if (($original['exact_value'] ?? null) !== $recovery->valor_original_exacto) {
            return ['estado' => 'pendiente',
                'motivo' => 'La nota original cambió después de la recuperación; requiere revisión.',
                'resultado_original' => $original];
        }
        $effective = app(GradeCalculationService::class)->result(
            ['mode' => 'MANUAL', 'manual' => $recovery->valor_efectivo_exacto], $config);

        return ['estado' => 'calculado', ...$effective,
            'origen' => 'recuperacion', 'resultado_original' => $original,
            'nota_recuperacion' => $recovery->nota_recuperacion,
            'politica_recuperacion' => $recovery->politica,
            'recuperacion_token' => \App\Support\OpaqueUrlToken::for('recuperacion-academica', $recovery->id)];
    }
}
