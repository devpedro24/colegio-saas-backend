<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Services\AcademicPlanAccess;
use App\Services\GradeCalculationService;
use App\Support\AcademicDecimal;
use App\Support\Audit\AuditLogger;
use App\Support\EvaluationOpaquePresenter as Presenter;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PreinformeController extends Controller
{
    public function index(Request $request, AcademicPlanAccess $plan): JsonResponse
    {
        abort_unless($request->user()->can('academico.preinformes.ver') || $request->user()->can('academico.preinformes.gestionar'), 403);
        $years = AnoLectivo::orderByDesc('fecha_inicio')->get();
        $year = $request->filled('ano_lectivo_token')
            ? OpaqueUrlToken::find('ano-lectivo', $request->string('ano_lectivo_token')->toString(), AnoLectivo::query())
            : ($years->firstWhere('estado', 'en_curso') ?? $years->first());
        abort_if($request->filled('ano_lectivo_token') && ! $year, 404);
        $periods = $year ? Periodo::where('ano_lectivo_id', $year->id)->orderBy('orden')->get() : collect();
        $pre = Preinforme::whereIn('periodo_id', $periods->modelKeys())->orderBy('orden')->get()->groupBy('periodo_id');

        return response()->json(['data' => [
            'anos' => $years->map(Presenter::year(...)), 'ano' => $year ? Presenter::year($year) : null,
            'incluido_plan' => $plan->preinformes(),
            'puede_gestionar' => $request->user()->can('academico.preinformes.gestionar') && $plan->preinformes(),
            'periodos' => $periods->map(fn ($period) => [
                ...Presenter::period($period), 'version' => $period->version_notas,
                'configuracion' => $period->configuracion_notas ?? ['usar_preinformes' => false, 'modo' => 'SIMPLE_AVERAGE', 'fechas_estrictas' => false],
                'editable' => ! $year->estaCerrado() && ! $period->estaCerrado(),
                'preinformes' => $pre->get($period->id, collect())->map(self::present(...))->values(),
            ]),
        ]]);
    }

    public static function present(Preinforme $pre): array
    {
        return ['url_token' => OpaqueUrlToken::for('preinforme', $pre->id), 'nombre' => $pre->nombre,
            'peso' => $pre->peso, 'fecha_inicio' => $pre->fecha_inicio, 'fecha_fin' => $pre->fecha_fin];
    }

    public function save(Request $request, string $periodo, AcademicPlanAccess $plan): JsonResponse
    {
        abort_unless($request->user()->can('academico.preinformes.gestionar'), 403);
        $plan->requirePreinformes();
        $rows = $request->input('preinformes', []);
        if (is_array($rows)) {
            foreach ($rows as &$row) {
                if (is_array($row) && isset($row['peso']) && is_string($row['peso'])) {
                    $row['peso'] = str_replace(',', '.', trim($row['peso']));
                }
                if (is_array($row) && isset($row['nombre']) && is_string($row['nombre'])) {
                    $row['nombre'] = trim($row['nombre']);
                }
            }
        }
        unset($row);
        $request->merge(['preinformes' => $rows]);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'usar_preinformes' => ['required', 'boolean'], 'fechas_estrictas' => ['required', 'boolean'],
            'modo' => ['required', Rule::in(['SIMPLE_AVERAGE', 'WEIGHTED_AVERAGE'])],
            'preinformes' => ['present', 'array', 'max:52'],
            'preinformes.*.url_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/', 'distinct'],
            'preinformes.*.nombre' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'preinformes.*.peso' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'preinformes.*.fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'preinformes.*.fecha_fin' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $period = OpaqueUrlToken::find('periodo', $periodo, Periodo::query());
        abort_unless($period, 404);
        DB::transaction(function () use ($period, $data, $request) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($period->ano_lectivo_id);
            $period = Periodo::lockForUpdate()->findOrFail($period->id);
            abort_if($year->estaCerrado() || $period->estaCerrado(), 422, 'El año o período está cerrado. Reábrelo antes de cambiar su configuración.');
            abort_unless($period->version_notas === $data['version'], 409, 'La configuración cambió en otra sesión. Recarga antes de guardar.');
            $before = ['configuracion' => $period->configuracion_notas, 'preinformes' => Preinforme::where('periodo_id', $period->id)->get()->toArray()];
            $enabled = $data['usar_preinformes'];
            $rows = $enabled ? $data['preinformes'] : [];
            abort_if($enabled && $rows === [], 422, 'Agrega al menos un preinforme.');
            if ($enabled && $data['modo'] === 'WEIGHTED_AVERAGE') {
                GradeCalculationService::assertWeights(array_column($rows, 'peso'));
            }
            $previousEnd = null;
            $keep = [];
            // Release the legacy unique component-name constraint while names
            // are reassigned. This also allows swapping two existing names.
            // All temporary names stay inside this locked transaction.
            $renameNonce = bin2hex(random_bytes(12));
            foreach (ComponenteEvaluacion::where('periodo_id', $period->id)->whereNotNull('preinforme_id')->get() as $component) {
                $component->update(['nombre' => '__pre_'.$renameNonce.'_'.$component->id]);
            }
            foreach ($rows as $index => $row) {
                $start = $row['fecha_inicio'] ?? null;
                $end = $row['fecha_fin'] ?? null;
                abort_if(($start === null) !== ($end === null), 422, 'Completa ambas fechas del preinforme o deja ambas vacías.');
                abort_if($data['fechas_estrictas'] && ! $start, 422, 'Los preinformes con fechas obligatorias necesitan inicio y fin.');
                abort_if($start && ($start > $end || $start < $period->fecha_inicio->toDateString() || $end > $period->fecha_fin->toDateString()), 422, 'Las fechas del preinforme deben estar dentro del período y en orden.');
                abort_if($start && $previousEnd && $start <= $previousEnd, 422, 'Las fechas de los preinformes no pueden cruzarse.');
                if ($end) {
                    $previousEnd = $end;
                }
                $pre = ! empty($row['url_token']) ? OpaqueUrlToken::find('preinforme', $row['url_token'], Preinforme::where('periodo_id', $period->id)) : new Preinforme;
                abort_unless($pre, 404);
                if ($pre->exists && $data['fechas_estrictas']) {
                    $componentIds = ComponenteEvaluacion::where('preinforme_id', $pre->id)->pluck('id');
                    abort_if(DB::table('actividades_evaluacion')->whereIn('componente_id', $componentIds)->where(fn ($q) => $q->where('fecha', '<', $start)->orWhere('fecha', '>', $end))->exists(), 422, 'Hay actividades fuera de las nuevas fechas del preinforme.');
                }
                $pre->fill(['periodo_id' => $period->id, 'nombre' => trim($row['nombre']), 'orden' => $index + 1,
                    'peso' => $data['modo'] === 'WEIGHTED_AVERAGE' ? AcademicDecimal::normalize($row['peso']) : null,
                    'fecha_inicio' => $start, 'fecha_fin' => $end])->save();
                $keep[] = $pre->id;
                foreach (ComponenteEvaluacion::where('preinforme_id', $pre->id)->get() as $component) {
                    $component->update(['nombre' => $pre->nombre, 'peso' => $pre->peso, 'version' => $component->version + 1]);
                }
            }
            $removed = Preinforme::where('periodo_id', $period->id)->whereNotIn('id', $keep)->get();
            foreach ($removed as $pre) {
                $components = ComponenteEvaluacion::where('preinforme_id', $pre->id)->get();
                abort_if(DB::table('actividades_evaluacion')->whereIn('componente_id', $components->modelKeys())->exists(), 422, 'Este preinforme contiene actividades; no se puede eliminar ni desactivar sin resolverlas.');
                foreach ($components as $component) {
                    $component->delete();
                }
                $pre->delete();
            }
            if ($enabled) {
                $legacy = ComponenteEvaluacion::where('periodo_id', $period->id)->whereNull('preinforme_id')->get();
                abort_if(DB::table('actividades_evaluacion')->whereIn('componente_id', $legacy->modelKeys())->exists(), 422,
                    'El período ya tiene actividades directas o una evaluación anterior. No se modificaron sus notas; configura los preinformes antes de registrar actividades.');
                foreach ($legacy as $component) {
                    $component->delete();
                }
            }
            $period->update(['configuracion_notas' => ['usar_preinformes' => $enabled, 'modo' => $data['modo'], 'fechas_estrictas' => $data['fechas_estrictas']], 'version_notas' => $period->version_notas + 1]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'configuracion_preinformes', (string) $period->id, $before,
                ['configuracion' => $period->configuracion_notas, 'preinformes' => Preinforme::where('periodo_id', $period->id)->get()->toArray()]);
        });

        return response()->json(['data' => ['guardado' => true]]);
    }
}
