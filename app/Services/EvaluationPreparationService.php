<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Periodo;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\MetodoAprobacion;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Define evaluación por currículo y período, independiente de docentes o grupos. */
final class EvaluationPreparationService
{
    /** @return array<string, mixed> */
    public function read(AnoLectivo $year, object $curriculum, Periodo $period): array
    {
        $preparation = DB::table('preparaciones_evaluacion')
            ->where('materia_curricular_id', $curriculum->id)->where('periodo_id', $period->id)->first();
        $components = $preparation ? DB::table('componentes_preparados')
            ->where('preparacion_id', $preparation->id)->orderBy('id')->get() : collect();
        $activities = $components->isNotEmpty() ? DB::table('actividades_preparadas')
            ->whereIn('componente_id', $components->pluck('id'))->orderBy('id')->get()->groupBy('componente_id') : collect();
        $rows = $components->map(fn ($component) => [
            'nombre' => $component->nombre, 'modo' => $component->modo,
            'peso' => $this->displayWeight($component->peso),
            'actividades' => $activities->get($component->id, collect())->map(fn ($activity) => [
                'nombre' => $activity->nombre, 'fecha' => $activity->fecha,
                'peso' => $this->displayWeight($activity->peso),
            ])->values()->all(),
        ])->values()->all();
        $issues = $this->issues($year, $rows);

        return [
            'version' => $preparation?->version ?? 0,
            'componentes' => $rows,
            'bloqueos' => $issues,
            'completa' => $issues === [],
            'aplicada' => $preparation?->applied_at !== null,
            'editable' => ! $year->estaCerrado() && ! $period->estaCerrado(),
        ];
    }

    /** @param array<int, array<string, mixed>> $components
     *  @return array<string, mixed>
     */
    public function save(AnoLectivo $year, object $curriculum, Periodo $period,
        array $components, int $expectedVersion, User $actor): array
    {
        $result = DB::transaction(function () use ($year, $curriculum, $period, $components, $expectedVersion, $actor): array {
            $year = AnoLectivo::query()->lockForUpdate()->findOrFail($year->id);
            $period = Periodo::where('ano_lectivo_id', $year->id)->lockForUpdate()->findOrFail($period->id);
            abort_if($period->configuracion_notas !== null, 422, 'Este período usa la nueva planilla. Configura los preinformes en Académico y las actividades desde Evaluación.');
            abort_if($year->estaCerrado() || $period->estaCerrado(), 422,
                'El año o período está cerrado; no se puede modificar la preparación de evaluación.');
            $this->validateRows($period, $components);
            $preparation = DB::table('preparaciones_evaluacion')
                ->where('materia_curricular_id', $curriculum->id)->where('periodo_id', $period->id)
                ->lockForUpdate()->first();
            abort_unless(($preparation?->version ?? 0) === $expectedVersion, 409,
                'La preparación cambió en otra sesión. Recárgala antes de guardar.');
            abort_if($preparation?->applied_at !== null, 422,
                'Esta preparación ya se aplicó a una planilla. No se puede modificar su definición histórica.');
            if ($preparation) {
                $sourceIds = DB::table('componentes_preparados')->where('preparacion_id', $preparation->id)->pluck('id');
                abort_if($sourceIds->isNotEmpty() && DB::table('componentes_evaluacion')
                    ->whereIn('componente_preparado_id', $sourceIds)->exists(), 422,
                    'Esta preparación ya se aplicó a una planilla. No se sobrescribieron sus componentes.');
            }
            $previous = $this->read($year, $curriculum, $period);
            $id = $preparation?->id ?? DB::table('preparaciones_evaluacion')->insertGetId([
                'materia_curricular_id' => $curriculum->id, 'periodo_id' => $period->id,
                'version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $sourceIds = DB::table('componentes_preparados')->where('preparacion_id', $id)->pluck('id');
            if ($sourceIds->isNotEmpty()) {
                DB::table('actividades_preparadas')->whereIn('componente_id', $sourceIds)->delete();
                DB::table('componentes_preparados')->where('preparacion_id', $id)->delete();
            }
            foreach ($components as $component) {
                $componentId = DB::table('componentes_preparados')->insertGetId([
                    'preparacion_id' => $id, 'nombre' => trim($component['nombre']),
                    'modo' => $component['modo'], 'peso' => $component['peso'] ?? null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($component['actividades'] ?? [] as $activity) {
                    DB::table('actividades_preparadas')->insert([
                        'componente_id' => $componentId, 'nombre' => trim($activity['nombre']),
                        'fecha' => $activity['fecha'], 'peso' => $activity['peso'] ?? null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            DB::table('preparaciones_evaluacion')->where('id', $id)->update([
                'version' => $expectedVersion + 1, 'updated_at' => now(),
            ]);
            $updated = $this->read($year, $curriculum, $period);
            AuditLogger::tenant($actor, $preparation ? 'UPDATE' : 'CREATE', 'preparacion_evaluacion',
                (string) $id, $previous, $updated);

            return $updated;
        });

        return $result;
    }

    /** @return array{creados:int, ya_existentes:int} */
    public function apply(AnoLectivo $year, AsignacionDocente $assignment, Periodo $period, User $actor): array
    {
        return DB::transaction(function () use ($year, $assignment, $period, $actor): array {
            $year = AnoLectivo::query()->lockForUpdate()->findOrFail($year->id);
            $period = Periodo::where('ano_lectivo_id', $year->id)->lockForUpdate()->findOrFail($period->id);
            $assignment = AsignacionDocente::query()->lockForUpdate()->findOrFail($assignment->id);
            abort_if($period->configuracion_notas !== null, 422, 'Este período usa la nueva planilla. Agrega las actividades directamente desde Evaluación.');
            abort_if($year->estaCerrado() || $period->estaCerrado(), 422,
                'El año o período está cerrado; no se puede aplicar la preparación.');
            abort_unless($assignment->ano_lectivo_id === $year->id
                && $assignment->grupo?->ano_lectivo_id === $year->id, 422,
                'La asignación no pertenece al año lectivo.');
            $curriculum = DB::table('materias_curriculares')->where('ano_lectivo_id', $year->id)
                ->where('grado_id', $assignment->grupo->grado_id)
                ->where('materia_id', $assignment->materia_id)->first();
            abort_unless($curriculum, 422, 'La materia no está en el currículo del grado de esta asignación.');
            $preparation = DB::table('preparaciones_evaluacion')
                ->where('materia_curricular_id', $curriculum->id)->where('periodo_id', $period->id)
                ->lockForUpdate()->first();
            abort_unless($preparation, 422, 'Primero configura la evaluación de la materia y el período.');
            $definition = $this->read($year, $curriculum, $period);
            abort_if(! $definition['completa'], 422,
                'La preparación está incompleta. Revisa componentes, actividades, pesos y SIEE.');

            $sources = DB::table('componentes_preparados')->where('preparacion_id', $preparation->id)->orderBy('id')->get();
            abort_if(ComponenteEvaluacion::where('asignacion_id', $assignment->id)
                ->where('periodo_id', $period->id)
                ->where(function ($query) use ($sources) {
                    $query->whereNull('componente_preparado_id')
                        ->orWhereNotIn('componente_preparado_id', $sources->pluck('id'));
                })->exists(), 422,
                'La planilla ya tiene componentes propios. No se mezcló ni sobrescribió la preparación.');
            $created = 0;
            $existing = 0;
            foreach ($sources as $source) {
                if (ComponenteEvaluacion::where('asignacion_id', $assignment->id)
                    ->where('periodo_id', $period->id)->where('componente_preparado_id', $source->id)->exists()) {
                    $existing++;
                    continue;
                }
                abort_if(ComponenteEvaluacion::where('asignacion_id', $assignment->id)
                    ->where('periodo_id', $period->id)->where('nombre', $source->nombre)->exists(), 422,
                    'La planilla ya tiene un componente con ese nombre. No se sobrescribió.');
                $component = ComponenteEvaluacion::create([
                    'asignacion_id' => $assignment->id, 'periodo_id' => $period->id,
                    'componente_preparado_id' => $source->id, 'nombre' => $source->nombre,
                    'modo' => $source->modo, 'peso' => $source->peso,
                ]);
                foreach (DB::table('actividades_preparadas')->where('componente_id', $source->id)->orderBy('id')->get() as $activity) {
                    ActividadEvaluacion::create(['componente_id' => $component->id,
                        'nombre' => $activity->nombre, 'fecha' => $activity->fecha, 'peso' => $activity->peso]);
                }
                $created++;
            }
            if ($created > 0) {
                DB::table('preparaciones_evaluacion')->where('id', $preparation->id)
                    ->whereNull('applied_at')->update(['applied_at' => now()]);
                AuditLogger::tenant($actor, 'CREATE', 'aplicacion_preparacion_evaluacion',
                    $assignment->id.':'.$period->id, null, [
                        'preparacion_id' => $preparation->id, 'componentes_creados' => $created,
                        'componentes_existentes' => $existing,
                    ]);
            }

            return ['creados' => $created, 'ya_existentes' => $existing];
        });
    }

    /** @param array<int, array<string, mixed>> $components
     *  @return list<string>
     */
    private function issues(AnoLectivo $year, array $components): array
    {
        $issues = [];
        $scaleId = $year->siee['escala_id'] ?? null;
        $methodId = $year->siee['metodo_id'] ?? null;
        $scale = $scaleId ? EscalaValorativa::where('ano_lectivo_id', $year->id)->find($scaleId) : null;
        $method = $methodId ? MetodoAprobacion::where('ano_lectivo_id', $year->id)->find($methodId) : null;
        if (! $scale || ! $method || $scale->tipo !== 'numerica'
            || $scale->valor_min >= $scale->valor_max
            || $method->nota_minima < $scale->valor_min || $method->nota_minima > $scale->valor_max) {
            $issues[] = 'siee_not_configured';
        }
        if ($components === []) {
            $issues[] = 'components_missing';
        }
        foreach ($components as $component) {
            if (($component['actividades'] ?? []) === []) {
                $issues[] = 'activities_missing';
            } elseif ($component['modo'] === 'WEIGHTED_AVERAGE'
                && ! $this->weightsValid(array_column($component['actividades'], 'peso'))) {
                $issues[] = 'activity_weights';
            }
        }
        if ($components !== [] && ($year->siee['modo_asignatura'] ?? SieeConfiguration::DEFAULTS['modo_asignatura']) === 'WEIGHTED_AVERAGE'
            && ! $this->weightsValid(array_column($components, 'peso'))) {
            $issues[] = 'component_weights';
        }

        return array_values(array_unique($issues));
    }

    private function weightsValid(array $weights): bool
    {
        try {
            GradeCalculationService::assertWeights($weights);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function displayWeight(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;

        return str_contains($text, '.') ? (rtrim(rtrim($text, '0'), '.') ?: '0') : $text;
    }

    /** @param array<int, array<string, mixed>> $components */
    private function validateRows(Periodo $period, array $components): void
    {
        $names = [];
        foreach ($components as $component) {
            $name = mb_strtolower(trim($component['nombre']));
            abort_if(isset($names[$name]), 422, 'Los nombres de los componentes no pueden repetirse.');
            $names[$name] = true;
            $activities = [];
            foreach ($component['actividades'] ?? [] as $activity) {
                $activityName = mb_strtolower(trim($activity['nombre']));
                abort_if(isset($activities[$activityName]), 422, 'Los nombres de las actividades no pueden repetirse dentro del componente.');
                $activities[$activityName] = true;
                abort_if($activity['fecha'] < $period->fecha_inicio->toDateString()
                    || $activity['fecha'] > $period->fecha_fin->toDateString(), 422,
                    'Cada actividad preparada debe estar dentro de las fechas del período.');
            }
        }
    }
}
