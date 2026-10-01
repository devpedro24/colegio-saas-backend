<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Support\EvaluationOpaquePresenter as Presenter;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Collection;

final class FlexibleGradingService
{
    public function sections(Periodo $period, Collection $components): array
    {
        if ($period->configuracion_notas['usar_preinformes'] ?? false) {
            return Preinforme::where('periodo_id', $period->id)->orderBy('orden')->get()->map(function ($pre) use ($components) {
                $component = $components->firstWhere('preinforme_id', $pre->id);

                return ['preinforme_token' => OpaqueUrlToken::for('preinforme', $pre->id),
                    'componente_token' => $component ? OpaqueUrlToken::for('componente-evaluacion', $component->id) : null,
                    'nombre' => $pre->nombre, 'peso' => $pre->peso, 'fecha_inicio' => $pre->fecha_inicio, 'fecha_fin' => $pre->fecha_fin,
                    'modo' => $component?->modo ?? 'SIMPLE_AVERAGE', 'version' => $component?->version ?? 0,
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

            return $query->firstOrCreate(['preinforme_id' => $pre->id], [
                'asignacion_id' => $assignment->id, 'periodo_id' => $period->id,
                'nombre' => $pre->nombre, 'peso' => $pre->peso, 'modo' => 'SIMPLE_AVERAGE', 'version' => 0,
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
