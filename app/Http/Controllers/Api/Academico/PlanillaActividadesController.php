<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Calificacion;
use App\Models\Academico\Preinforme;
use App\Services\FlexibleGradingService;
use App\Services\GradebookService;
use App\Support\AcademicDecimal;
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
        $data = $request->validate([
            'operacion' => ['required', Rule::in(['crear', 'editar', 'eliminar', 'modo'])],
            'componente_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'preinforme_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'actividad_token' => ['prohibited_unless:operacion,editar,eliminar', 'required_if:operacion,editar,eliminar', 'nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'version' => ['required', 'integer', 'min:0'],
            'nombre' => ['required_if:operacion,crear,editar', 'string', 'max:160'],
            'fecha' => ['required_if:operacion,crear,editar', 'date_format:Y-m-d'],
            'peso' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'modo' => ['required_if:operacion,modo', Rule::in(['SIMPLE_AVERAGE', 'WEIGHTED_AVERAGE'])],
        ]);
        $permission = $data['operacion'] === 'modo' ? 'notas.planilla.configurar' : 'notas.actividades.'.$data['operacion'];
        abort_unless($request->user()->can($permission), 403);
        DB::transaction(function () use ($assignment, $periodo, $data, $book, $sheets, $request) {
            $period = $book->writable($assignment, $periodo);
            $component = $sheets->section($assignment, $period, $data);
            if ($data['operacion'] === 'modo') {
                abort_unless($component->version === $data['version'], 409, 'La planilla cambió en otra sesión. Recarga antes de guardar.');
                $before = $component->toArray();
                $component->update(['modo' => $data['modo'], 'version' => $component->version + 1]);
                AuditLogger::tenant($request->user(), 'UPDATE', 'componente_evaluacion', (string) $component->id, $before, $component->toArray());

                return;
            }
            $activity = ! empty($data['actividad_token'])
                ? OpaqueUrlToken::find('actividad-evaluacion', $data['actividad_token'], ActividadEvaluacion::where('componente_id', $component->id))
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
            abort_if($component->modo === 'WEIGHTED_AVERAGE' && ! isset($data['peso']), 422, 'Indica el porcentaje de esta actividad.');
            abort_if($data['fecha'] < $period->fecha_inicio->toDateString() || $data['fecha'] > $period->fecha_fin->toDateString(), 422, 'La actividad debe estar dentro de las fechas del período.');
            if ($component->preinforme_id && ($period->configuracion_notas['fechas_estrictas'] ?? false)) {
                $pre = Preinforme::findOrFail($component->preinforme_id);
                abort_if($data['fecha'] < $pre->fecha_inicio || $data['fecha'] > $pre->fecha_fin, 422, 'La actividad debe estar dentro de las fechas de su preinforme.');
            }
            abort_if(ActividadEvaluacion::where('componente_id', $component->id)->where('nombre', trim($data['nombre']))
                ->when($activity->exists, fn ($q) => $q->where('id', '!=', $activity->id))->exists(), 422, 'Ya existe una actividad con ese nombre en esta sección.');
            $activity->fill(['componente_id' => $component->id, 'nombre' => trim($data['nombre']), 'fecha' => $data['fecha'],
                'peso' => $component->modo === 'WEIGHTED_AVERAGE' ? AcademicDecimal::normalize($data['peso'] ?? null) : null,
                'version' => ($activity->version ?? 0) + 1])->save();
            AuditLogger::tenant($request->user(), $before ? 'UPDATE' : 'CREATE', 'actividad_evaluacion', (string) $activity->id, $before, $activity->toArray());
        });

        return response()->json(['data' => ['guardado' => true]]);
    }
}
