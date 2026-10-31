<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Services\FlexibleGradingService;
use App\Services\GradebookService;
use App\Services\SieeConfiguration;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PlanillaActividadesController extends Controller
{
    public function save(Request $request, int $asignacion, int $periodo, GradebookService $book, FlexibleGradingService $sheets): JsonResponse
    {
        $assignment = AsignacionDocente::findOrFail($asignacion);
        $book->authorizeAssignment($request->user(), $assignment, write: true);
        if (is_string($request->input('peso'))) {
            $request->merge(['peso' => str_replace(',', '.', trim($request->input('peso')))]);
        }
        if (is_array($request->input('actividades'))) {
            $rows = $request->input('actividades');
            foreach ($rows as &$row) {
                if (is_array($row) && is_string($row['peso'] ?? null)) {
                    $row['peso'] = str_replace(',', '.', trim($row['peso']));
                }
            }
            unset($row);
            $request->merge(['actividades' => $rows]);
        }
        $data = $request->validate([
            'operacion' => ['required', Rule::in(['crear', 'editar', 'editar_lote', 'eliminar', 'modo'])],
            'componente_token' => [Rule::requiredIf(fn () => $request->input('operacion') === 'editar_lote' && ! $request->filled('preinforme_token')), 'nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'preinforme_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'actividad_token' => ['prohibited_unless:operacion,editar,eliminar', 'required_if:operacion,editar,eliminar', 'nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'version' => ['required', 'integer', 'min:0'],
            'nombre' => ['required_if:operacion,crear,editar', 'string', 'max:160'],
            'fecha' => ['required_if:operacion,crear,editar', 'date_format:Y-m-d'],
            'peso' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'modo' => ['required_if:operacion,modo', Rule::in(['SIMPLE_AVERAGE', 'WEIGHTED_AVERAGE', 'MANUAL'])],
            'actividades' => ['prohibited_unless:operacion,editar_lote', 'required_if:operacion,editar_lote', 'array', 'list', 'min:1', 'max:1000'],
            'actividades.*' => ['required', 'array:actividad_token,version,nombre,fecha,peso'],
            'actividades.*.actividad_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/', 'distinct:strict'],
            'actividades.*.version' => ['required', 'integer', 'min:0'],
            'actividades.*.nombre' => ['required', 'string', 'max:160'],
            'actividades.*.fecha' => ['required', 'date_format:Y-m-d'],
            'actividades.*.peso' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
        ]);
        $permission = match ($data['operacion']) {
            'modo' => 'notas.planilla.configurar',
            'editar_lote' => 'notas.actividades.editar',
            default => 'notas.actividades.'.$data['operacion'],
        };
        abort_unless($request->user()->can($permission), 403);
        DB::transaction(function () use ($assignment, $periodo, $data, $book, $sheets, $request) {
            $period = $book->writable($assignment, $periodo);
            $component = $sheets->section($assignment, $period, $data);
            if ($data['operacion'] === 'editar_lote') {
                abort_unless($component->version === $data['version'], 409, 'La planilla cambió en otra sesión. Recarga antes de guardar.');
                $this->editBatch($request, $component, $period, $data['actividades'], $sheets);

                return;
            }
            if ($data['operacion'] === 'modo') {
                if ($data['modo'] === 'MANUAL') {
                    $config = app(SieeConfiguration::class)->resolve(
                        AnoLectivo::findOrFail($assignment->ano_lectivo_id));
                    abort_if($config['modo_asignatura'] === 'MANUAL', 422,
                        'La definitiva de materia ya es manual; no se usa el cálculo de componentes.');
                }
                abort_unless($component->version === $data['version'], 409, 'La planilla cambió en otra sesión. Recarga antes de guardar.');
                if ($data['modo'] === 'MANUAL' && $component->modo !== 'MANUAL') {
                    abort_if($component->actividades()->exists(), 422,
                        'Elimina primero las actividades sin notas. Un componente manual no puede ocultarlas.');
                }
                if ($component->modo === 'MANUAL' && $data['modo'] !== 'MANUAL') {
                    abort_if(DB::table('resultados_manuales_componente')->where('componente_id', $component->id)
                        ->where('estado', 'vigente')->exists(), 422,
                        'Hay resultados manuales vigentes. Anúlalos justificadamente antes de cambiar el modo.');
                }
                $sheets->changeMode($component, $period, $data['modo'], $request->user());

                return;
            }
            $activity = ! empty($data['actividad_token'])
                ? OpaqueUrlToken::find('actividad-evaluacion', $data['actividad_token'], ActividadEvaluacion::where('componente_id', $component->id)->lockForUpdate())
                : new ActividadEvaluacion;
            abort_unless($activity, 404);
            abort_unless(($activity->exists ? $activity->version : 0) === $data['version'], 409, 'La actividad cambió en otra sesión. Recarga antes de guardar.');
            $before = $activity->exists ? $activity->toArray() : null;
            if ($data['operacion'] === 'eliminar') {
                abort_if(Calificacion::where('actividad_id', $activity->id)->exists(), 422, 'La actividad tiene calificaciones registradas. Su historial debe conservarse.');
                AuditLogger::tenant($request->user(), 'DELETE', 'actividad_evaluacion', (string) $activity->id, $before, null);
                $activity->delete();

                return;
            }
            abort_if($component->modo === 'MANUAL', 422,
                'Este componente usa un resultado manual y no admite actividades.');
            abort_if($component->modo === 'WEIGHTED_AVERAGE' && ! isset($data['peso']), 422, 'Indica el porcentaje de esta actividad.');
            $weight = $sheets->activityWeight($component, $data['peso'] ?? null, $activity);
            $sheets->assertActivityWeight($component, $weight, $activity->exists ? $activity->id : null);
            $this->assertDate($component, $period, $data['fecha']);
            abort_if(ActividadEvaluacion::where('componente_id', $component->id)->where('nombre', trim($data['nombre']))
                ->when($activity->exists, fn ($q) => $q->where('id', '!=', $activity->id))->exists(), 422, 'Ya existe una actividad con ese nombre en esta sección.');
            $activity->fill(['componente_id' => $component->id, 'nombre' => trim($data['nombre']), 'fecha' => $data['fecha'],
                'peso' => $weight,
                'version' => ($activity->version ?? 0) + 1])->save();
            AuditLogger::tenant($request->user(), $before ? 'UPDATE' : 'CREATE', 'actividad_evaluacion', (string) $activity->id, $before, $activity->toArray());
        });

        return response()->json(['data' => ['guardado' => true]]);
    }

    /** Runs under the same year/period locks and transaction as every other sheet write. */
    private function editBatch(Request $request, ComponenteEvaluacion $component, Periodo $period, array $rows, FlexibleGradingService $sheets): void
    {
        abort_if($component->modo === 'MANUAL', 422, 'Este componente usa un resultado manual y no admite actividades.');
        $query = ActividadEvaluacion::where('componente_id', $component->id);
        $activities = (clone $query)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $ids = OpaqueUrlToken::ids('actividad-evaluacion', array_column($rows, 'actividad_token'), clone $query);
        abort_unless(count($ids) === count($rows), 404);
        abort_unless($activities->count() === count($rows), 409, 'Las actividades de la sección cambiaron. Recarga antes de guardar.');
        $names = [];
        $changes = [];
        foreach ($rows as $row) {
            $activity = $activities[$ids[$row['actividad_token']]];
            abort_unless($activity->version === $row['version'], 409, 'La actividad cambió en otra sesión. Recarga antes de guardar.');
            $this->assertDate($component, $period, $row['fecha']);
            $name = trim($row['nombre']);
            abort_if($name === '' || in_array($name, $names, true), 422, 'Los nombres de las actividades deben ser únicos y no vacíos en esta sección.');
            $names[] = $name;
            $changes[$activity->id] = ['nombre' => $name, 'fecha' => $row['fecha'],
                'peso' => $sheets->activityWeight($component, $row['peso'] ?? null, $activity),
                'version' => $activity->version + 1];
        }
        // Validate the FINAL set once: 20/80 -> 90/10 must not fail on an intermediate 170.
        $sheets->assertActivityWeights(array_column($changes, 'peso'),
            requireAll: $component->modo === 'WEIGHTED_AVERAGE', allowZero: $component->modo === 'SIMPLE_AVERAGE');
        foreach ($changes as $id => $attributes) {
            $activity = $activities[$id];
            $before = $activity->toArray();
            $activity->fill($attributes)->save();
            AuditLogger::tenant($request->user(), 'UPDATE', 'actividad_evaluacion', (string) $activity->id, $before, $activity->toArray());
        }
    }

    private function assertDate(ComponenteEvaluacion $component, Periodo $period, string $date): void
    {
        abort_if($date < $period->fecha_inicio->toDateString() || $date > $period->fecha_fin->toDateString(), 422, 'La actividad debe estar dentro de las fechas del período.');
        if ($component->preinforme_id && ($period->configuracion_notas['fechas_estrictas'] ?? false)) {
            $pre = Preinforme::findOrFail($component->preinforme_id);
            abort_if($date < $pre->fecha_inicio || $date > $pre->fecha_fin, 422, 'La actividad debe estar dentro de las fechas de su preinforme.');
        }
    }
}
