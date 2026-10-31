<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Models\User;
use App\Support\AcademicDecimal;
use App\Support\Audit\AuditLogger;
use App\Support\EvaluationOpaquePresenter as Presenter;
use App\Support\OpaqueUrlToken;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

final class FlexibleGradingService
{
    /** Null/omitted weights in simple mode must not erase a saved percentage. */
    public function activityWeight(ComponenteEvaluacion $component, mixed $weight, ?ActividadEvaluacion $activity = null): ?string
    {
        return AcademicDecimal::normalize($component->modo === 'SIMPLE_AVERAGE' && $weight === null ? $activity?->peso : $weight);
    }

    public function assertActivityWeight(ComponenteEvaluacion $component, mixed $weight, ?int $except = null): void
    {
        $weights = $component->actividades()->when($except, fn ($q) => $q->where('id', '!=', $except))->pluck('peso')->all();
        // Other unconfigured activities may remain pending after a mode change.
        abort_if($component->modo === 'WEIGHTED_AVERAGE' && $weight === null, 422,
            'Cada actividad ponderada necesita un porcentaje mayor que cero.');
        $this->assertActivityWeights([...$weights, $weight], allowZero: $component->modo === 'SIMPLE_AVERAGE');
    }

    /** Partial totals are allowed for drafts; the calculation engine still requires exactly 100. */
    public function assertActivityWeights(array $weights, bool $requireAll = false, bool $allowZero = false): void
    {
        $sum = BigDecimal::zero();
        foreach ($weights as $weight) {
            abort_if($requireAll && $weight === null, 422, 'Cada actividad ponderada necesita un porcentaje mayor que cero.');
            if ($weight === null) {
                continue;
            }
            $decimal = BigDecimal::of((string) $weight);
            abort_if(! $allowZero && $decimal->isZero(), 422,
                'Hay actividades con porcentaje cero. Corrige sus pesos en promedio simple antes de activar o guardar el promedio ponderado.');
            abort_if($decimal->isLessThan(0) || $decimal->isGreaterThan(100), 422,
                'Cada porcentaje de actividad debe ser mayor que cero y no superar el 100 %.');
            $sum = $sum->plus($decimal);
        }
        abort_if($sum->isGreaterThan(100), 422,
            'Los porcentajes de las actividades de este preinforme no pueden superar el 100 %.');
    }

    /** All automatic pre-reports of this assignment/period share the same activity mode. */
    public function changeMode(ComponenteEvaluacion $selected, Periodo $period, string $mode, ?User $actor): void
    {
        $components = collect([$selected]);
        if ($selected->preinforme_id && $mode !== 'MANUAL') {
            foreach (Preinforme::where('periodo_id', $period->id)->get() as $pre) {
                $component = $this->section($selected->asignacion, $period, ['preinforme_token' => OpaqueUrlToken::for('preinforme', $pre->id)]);
                if ($component->id !== $selected->id && $component->modo !== 'MANUAL') {
                    $components->push($component);
                }
            }
        }
        foreach ($components as $component) {
            if ($component->modo === $mode) {
                continue;
            }
            $before = $component->toArray();
            $activities = $component->actividades()->orderBy('id')->lockForUpdate()->get();
            // Only a section without saved percentages gets an initial equal split.
            // Added/unconfigured activities stay pending; never rescale custom weights.
            $initialize = $mode === 'WEIGHTED_AVERAGE' && $activities->isNotEmpty()
                && $activities->every(fn ($activity) => $activity->peso === null);
            if ($mode === 'WEIGHTED_AVERAGE') {
                $this->assertActivityWeights($activities->pluck('peso')->all());
            }
            $equal = $initialize ? BigDecimal::of(100)->dividedBy($activities->count(), 4, RoundingMode::Down) : null;
            $remaining = BigDecimal::of(100);
            foreach ($activities as $index => $activity) {
                $previous = $activity->toArray();
                $weight = $activity->peso;
                if ($initialize) {
                    $weight = $index === $activities->count() - 1 ? $remaining : $equal;
                    $remaining = $remaining->minus($weight);
                }
                $activity->update(['peso' => $weight === null ? null : (string) $weight, 'version' => $activity->version + 1]);
                AuditLogger::tenant($actor, 'UPDATE', 'actividad_evaluacion', (string) $activity->id, $previous, $activity->toArray());
            }
            $component->update(['modo' => $mode, 'version' => $component->version + 1]);
            AuditLogger::tenant($actor, 'UPDATE', 'componente_evaluacion', (string) $component->id, $before, $component->toArray());
        }
    }

    public function sections(Periodo $period, Collection $components): array
    {
        if ($period->configuracion_notas['usar_preinformes'] ?? false) {
            $defaultMode = $components->contains('modo', 'WEIGHTED_AVERAGE') ? 'WEIGHTED_AVERAGE' : 'SIMPLE_AVERAGE';

            return Preinforme::where('periodo_id', $period->id)->orderBy('orden')->get()->map(function ($pre) use ($components, $defaultMode) {
                $component = $components->firstWhere('preinforme_id', $pre->id);

                return ['preinforme_token' => OpaqueUrlToken::for('preinforme', $pre->id),
                    'componente_token' => $component ? OpaqueUrlToken::for('componente-evaluacion', $component->id) : null,
                    'nombre' => $pre->nombre, 'peso' => $pre->peso, 'fecha_inicio' => $pre->fecha_inicio, 'fecha_fin' => $pre->fecha_fin,
                    'modo' => $component?->modo ?? $defaultMode, 'version' => $component?->version ?? 0,
                    'actividades' => $component?->actividades->map(Presenter::activity(...))->all() ?? []];
            })->all();
        }
        if ($components->isEmpty()) {
            return [['preinforme_token' => null, 'componente_token' => null, 'nombre' => null,
                'peso' => null, 'modo' => 'SIMPLE_AVERAGE', 'version' => 0, 'actividades' => []]];
        }

        return $components->map(fn ($component) => [
            'preinforme_token' => null, 'componente_token' => OpaqueUrlToken::for('componente-evaluacion', $component->id),
            'nombre' => $component->es_directo ? null : $component->nombre, 'peso' => $component->peso,
            'modo' => $component->modo, 'version' => $component->version,
            'actividades' => $component->actividades->map(Presenter::activity(...))->all(),
        ])->all();
    }

    /** Called only inside an authorized write transaction holding the year/period locks. */
    public function section(AsignacionDocente $assignment, Periodo $period, array $data): ComponenteEvaluacion
    {
        $query = ComponenteEvaluacion::where('asignacion_id', $assignment->id)->where('periodo_id', $period->id);
        $enabled = $period->configuracion_notas['usar_preinformes'] ?? false;
        if ($enabled) {
            app(AcademicPlanAccess::class)->requirePreinformes();
        }
        if (! empty($data['componente_token'])) {
            $component = OpaqueUrlToken::find('componente-evaluacion', $data['componente_token'], $query);
            abort_unless($component, 404);
            abort_if($enabled !== ($component->preinforme_id !== null), 422, 'La configuración del período cambió. Recarga la planilla.');
            if (! empty($data['preinforme_token'])) {
                $pre = OpaqueUrlToken::find('preinforme', $data['preinforme_token'], Preinforme::where('periodo_id', $period->id));
                abort_unless($pre && $component->preinforme_id === $pre->id, 404);
            }

            return $component;
        }
        if ($enabled) {
            $pre = ! empty($data['preinforme_token']) ? OpaqueUrlToken::find('preinforme', $data['preinforme_token'], Preinforme::where('periodo_id', $period->id)) : null;
            abort_unless($pre, 404);

            $mode = (clone $query)->whereNotNull('preinforme_id')->where('modo', '!=', 'MANUAL')
                ->orderByRaw("CASE WHEN modo = 'WEIGHTED_AVERAGE' THEN 0 ELSE 1 END")->value('modo') ?? 'SIMPLE_AVERAGE';

            return $query->firstOrCreate(['preinforme_id' => $pre->id], [
                'asignacion_id' => $assignment->id, 'periodo_id' => $period->id,
                'nombre' => $pre->nombre, 'peso' => $pre->peso, 'modo' => $mode, 'version' => 0,
            ]);
        }
        abort_if(! empty($data['preinforme_token']), 404);
        abort_if((clone $query)->count() > 1, 422, 'Selecciona la sección de la planilla.');

        return $query->first() ?? ComponenteEvaluacion::create([
            'asignacion_id' => $assignment->id, 'periodo_id' => $period->id, 'nombre' => 'Notas del período',
            'modo' => 'SIMPLE_AVERAGE', 'peso' => 100, 'es_directo' => true, 'version' => 0,
        ]);
    }
}
