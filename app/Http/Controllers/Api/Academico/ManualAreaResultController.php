<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Services\EscalaVisualService;
use App\Services\GradebookService;
use App\Services\SieeConfiguration;
use App\Support\Audit\AuditLogger;
use App\Support\EvaluationOpaquePresenter as PublicEval;
use App\Support\OpaqueUrlToken;
use App\Support\StudentRosterName;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ManualAreaResultController extends Controller
{
    public function index(Request $request, GradebookService $book, EscalaVisualService $visual): JsonResponse
    {
        $data = $request->validate([
            'grupo_token' => ['required', 'string'], 'periodo_token' => ['required', 'string'],
            'area_token' => ['nullable', 'string'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $group = Grupo::with('grado')->findOrFail($request->integer('grupo_id'));
        $period = Periodo::where('ano_lectivo_id', $group->ano_lectivo_id)->findOrFail($request->integer('periodo_id'));
        $year = AnoLectivo::findOrFail($group->ano_lectivo_id);
        $config = app(SieeConfiguration::class)->resolve($year);
        abort_unless($config['usar_areas'] && $config['modo_area'] === 'MANUAL', 422,
            'Este año no utiliza resultados manuales de área.');
        $areas = DB::table('materias_curriculares as mc')->join('areas as a', 'a.id', '=', 'mc.area_id')
            ->where('mc.ano_lectivo_id', $year->id)->where('mc.grado_id', $group->grado_id)
            ->whereNotNull('mc.area_id')->select('a.id', 'a.nombre')->distinct()->orderBy('a.nombre')->get();
        $area = $request->filled('area_id') ? $areas->firstWhere('id', $request->integer('area_id')) : $areas->first();
        abort_if($request->filled('area_token') && ! $area, 404);
        $roster = Matricula::with('estudiante:id,name')->where('ano_lectivo_id', $year->id)
            ->where('grupo_id', $group->id)->where('estado', 'activa')->get()
            ->sort(fn (Matricula $a, Matricula $b) => strcmp(StudentRosterName::sortKey($a->estudiante->name),
                StudentRosterName::sortKey($b->estudiante->name)) ?: ($a->id <=> $b->id))->values();
        $page = min((int) ($data['page'] ?? 1), max(1, (int) ceil($roster->count() / 50)));
        $students = $roster->forPage($page, 50)->values();
        $manuals = $area ? DB::table('resultados_manuales_area')->where('area_id', $area->id)
            ->where('periodo_id', $period->id)->whereIn('matricula_id', $students->modelKeys())->get()
            ->keyBy('matricula_id') : collect();
        $scale = $visual->forGroup($group);

        return response()->json(['data' => [
            'grupo' => ['url_token' => OpaqueUrlToken::for('grupo', $group->id),
                'nombre' => $group->grado->nombre.' · '.$group->nombre],
            'periodo' => ['url_token' => OpaqueUrlToken::for('periodo', $period->id),
                'nombre' => $period->nombre, 'estado' => $period->estado],
            'areas' => $areas->map(fn ($item) => ['url_token' => OpaqueUrlToken::for('area', $item->id),
                'nombre' => $item->nombre])->all(),
            'area_token' => $area ? OpaqueUrlToken::for('area', $area->id) : null,
            'editable' => $year->estado === 'en_curso' && $period->estado === 'abierto',
            'valor_min' => $config['valor_min'], 'valor_max' => $config['valor_max'],
            'escala_visual' => $visual->present($scale),
            'matriculas' => $students->map(function (Matricula $student) use ($book, $manuals, $config, $visual, $scale) {
                $result = $book->manualAreaResult($manuals->get($student->id), $config);
                if ($scale) $result = $visual->decorate($result, $scale->opciones);
                return ['url_token' => OpaqueUrlToken::for('matricula', $student->id),
                    'nombre_lista' => StudentRosterName::display($student->estudiante->name),
                    'resultado' => PublicEval::result($result, $scale !== null)];
            })->all(),
            'pagination' => ['page' => $page, 'per_page' => 50, 'total' => $roster->count(),
                'last_page' => max(1, (int) ceil($roster->count() / 50))],
        ]]);
    }

    public function update(Request $request, int $grupo, int $periodo, int $area, int $matricula,
        EscalaVisualService $visual): JsonResponse
    {
        $data = $request->validate([
            'valor' => ['present', 'nullable', 'numeric', 'decimal:0,8'],
            'escala_opcion_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $reason = trim($data['motivo']);
        abort_if(mb_strlen($reason) < 10, 422, 'Escribe un motivo claro de al menos diez caracteres.');
        $group = Grupo::findOrFail($grupo);
        $selectedArea = Area::where('ano_lectivo_id', $group->ano_lectivo_id)->findOrFail($area);
        $version = DB::transaction(function () use ($request, $data, $reason, $group, $periodo, $selectedArea, $matricula, $visual) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($group->ano_lectivo_id);
            $period = Periodo::where('ano_lectivo_id', $year->id)->lockForUpdate()->findOrFail($periodo);
            abort_unless($year->estado === 'en_curso' && $period->estado === 'abierto', 422,
                'El año debe estar en curso y el período abierto para registrar resultados.');
            $enrollment = Matricula::where('ano_lectivo_id', $year->id)
                ->where('grupo_id', $group->id)->where('estado', 'activa')->lockForUpdate()->findOrFail($matricula);
            abort_unless(DB::table('materias_curriculares')->where('ano_lectivo_id', $year->id)
                ->where('grado_id', $group->grado_id)->where('area_id', $selectedArea->id)->exists(), 422,
                'El área no pertenece al currículo de este grupo.');
            $config = app(SieeConfiguration::class)->resolve($year);
            abort_unless($config['usar_areas'] && $config['modo_area'] === 'MANUAL', 422,
                'El SIEE de este año no habilita resultados manuales de área.');
            $scale = $visual->forGroup($group);
            $manualValue = $data['valor'];
            if ($scale) {
                abort_if($manualValue !== null, 422, 'Selecciona una categoría visual, no una nota numérica.');
                $token = $data['escala_opcion_token'] ?? null;
                $choice = $token ? $scale->opciones->first(fn ($item) =>
                    OpaqueUrlToken::for('escala-opcion', $item->id) === $token) : null;
                abort_if($token && ! $choice, 422, 'La categoría no pertenece a la escala visual del grupo.');
                $manualValue = $choice?->valor_equivalente;
            } else {
                abort_if(isset($data['escala_opcion_token']), 422, 'Esta área usa una escala numérica.');
            }
            if ($manualValue !== null) {
                $value = BigDecimal::of((string) $manualValue);
                abort_if($value->isLessThan((string) $config['valor_min'])
                    || $value->isGreaterThan((string) $config['valor_max']), 422,
                    'El resultado está fuera de la escala configurada.');
            }
            $key = ['matricula_id' => $enrollment->id, 'area_id' => $selectedArea->id, 'periodo_id' => $period->id];
            $previous = DB::table('resultados_manuales_area')->where($key)->lockForUpdate()->first();
            abort_unless((int) ($previous->version ?? 0) === (int) $data['version'], 409,
                'Otra persona modificó este resultado. Recarga la planilla.');
            abort_if($manualValue === null && (! $previous || $previous->estado !== 'vigente'), 422,
                'No hay un resultado manual vigente para anular.');
            $values = ['valor' => $manualValue, 'estado' => $manualValue === null ? 'anulado' : 'vigente',
                'motivo' => $reason, 'version' => (int) ($previous->version ?? 0) + 1,
                'updated_by' => $request->user()->id, 'updated_at' => now()];
            if ($previous) {
                DB::table('resultados_manuales_area')->where('id', $previous->id)->update($values);
                $id = $previous->id;
            } else {
                $id = DB::table('resultados_manuales_area')->insertGetId([...$key, ...$values, 'created_at' => now()]);
            }
            AuditLogger::tenant($request->user(), $manualValue === null ? 'ANULAR' : ($previous ? 'UPDATE' : 'CREATE'),
                'resultado_manual_area', (string) $id, $previous ? (array) $previous : null,
                [...$key, ...$values], $reason);
            return $values['version'];
        });

        return response()->json(['data' => ['version' => $version]]);
    }
}
