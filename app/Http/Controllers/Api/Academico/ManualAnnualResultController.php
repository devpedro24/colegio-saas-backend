<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
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
use Illuminate\Validation\Rule;

final class ManualAnnualResultController extends Controller
{
    public function index(Request $request, GradebookService $book, EscalaVisualService $visual): JsonResponse
    {
        $data = $request->validate([
            'grupo_token' => ['required', 'string'], 'ambito' => ['required', Rule::in(['materia', 'area'])],
            'materia_token' => ['nullable', 'string'], 'area_token' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $group = Grupo::with('grado')->findOrFail($request->integer('grupo_id'));
        $year = AnoLectivo::findOrFail($group->ano_lectivo_id);
        $config = app(SieeConfiguration::class)->resolve($year);
        abort_unless($config['modo_anual'] === 'MANUAL', 422, 'Este año no utiliza definitivas anuales manuales.');
        abort_if($data['ambito'] === 'area' && ! $config['usar_areas'], 422,
            'Este año no usa resultados de área.');
        abort_if($data['ambito'] === 'materia' && $request->filled('area_token'), 422);
        abort_if($data['ambito'] === 'area' && $request->filled('materia_token'), 422);
        $resources = $this->resources($group, $data['ambito']);
        $resourceId = $request->integer($data['ambito'].'_id');
        $selected = $resourceId ? $resources->firstWhere('id', $resourceId) : $resources->first();
        abort_if($resourceId && ! $selected, 404);
        $roster = Matricula::with('estudiante:id,name')->where('ano_lectivo_id', $year->id)
            ->where('grupo_id', $group->id)->where('estado', 'activa')->get()
            ->sort(fn (Matricula $a, Matricula $b) => strcmp(StudentRosterName::sortKey($a->estudiante->name),
                StudentRosterName::sortKey($b->estudiante->name)) ?: ($a->id <=> $b->id))->values();
        $lastPage = max(1, (int) ceil($roster->count() / 50));
        $page = min((int) ($data['page'] ?? 1), $lastPage);
        $students = $roster->forPage($page, 50)->values();
        $manuals = $selected ? DB::table('resultados_manuales_anuales')
            ->where($data['ambito'].'_id', $selected->id)->whereIn('matricula_id', $students->modelKeys())
            ->get()->keyBy('matricula_id') : collect();
        $periods = Periodo::where('ano_lectivo_id', $year->id)->get();
        $editable = $year->estado === 'en_curso' && $periods->count() === $year->num_periodos
            && $periods->every(fn (Periodo $period) => $period->estado === 'cerrado');
        $scale = $visual->forGroup($group);

        return response()->json(['data' => [
            'grupo' => ['url_token' => OpaqueUrlToken::for('grupo', $group->id),
                'nombre' => $group->grado->nombre.' · '.$group->nombre],
            'ambito' => $data['ambito'],
            'recursos' => $resources->map(fn ($item) => ['url_token' => OpaqueUrlToken::for($data['ambito'], $item->id),
                'nombre' => $item->nombre])->all(),
            'recurso_token' => $selected ? OpaqueUrlToken::for($data['ambito'], $selected->id) : null,
            'editable' => $editable, 'valor_min' => $config['valor_min'], 'valor_max' => $config['valor_max'],
            'escala_visual' => $visual->present($scale),
            'matriculas' => $students->map(function (Matricula $student) use ($book, $manuals, $config, $visual, $scale) {
                $manual = $manuals->get($student->id);
                $result = $manual && $manual->estado === 'vigente' && $manual->valor !== null
                    ? [...$book->calculate(['mode' => 'MANUAL', 'manual' => (string) $manual->valor], $config),
                        'origen' => 'manual', 'manual_version' => (int) $manual->version]
                    : ['estado' => 'pendiente', 'manual_version' => (int) ($manual->version ?? 0)];
                if ($scale) $result = $visual->decorate($result, $scale->opciones);

                return ['url_token' => OpaqueUrlToken::for('matricula', $student->id),
                    'nombre_lista' => StudentRosterName::display($student->estudiante->name),
                    'resultado' => PublicEval::result($result, $scale !== null)];
            })->all(),
            'pagination' => ['page' => $page, 'per_page' => 50, 'total' => $roster->count(), 'last_page' => $lastPage],
        ]]);
    }

    public function update(Request $request, int $grupo, int $matricula, GradebookService $book,
        EscalaVisualService $visual): JsonResponse
    {
        $data = $request->validate([
            'ambito' => ['required', Rule::in(['materia', 'area'])],
            'materia_token' => ['nullable', 'string'], 'area_token' => ['nullable', 'string'],
            'valor' => ['present', 'nullable', 'numeric', 'decimal:0,8'],
            'escala_opcion_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $reason = trim($data['motivo']);
        abort_if(mb_strlen($reason) < 10, 422, 'Escribe un motivo claro de al menos diez caracteres.');
        $scope = $data['ambito'];
        abort_unless($request->filled($scope.'_token') && ! $request->filled(($scope === 'materia' ? 'area' : 'materia').'_token'), 422,
            'Selecciona una sola materia o área.');
        $resourceId = $request->integer($scope.'_id');
        $group = Grupo::findOrFail($grupo);

        $version = DB::transaction(function () use ($request, $data, $reason, $group, $matricula,
            $book, $visual, $scope, $resourceId) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($group->ano_lectivo_id);
            $periods = Periodo::where('ano_lectivo_id', $year->id)->lockForUpdate()->get();
            abort_unless($year->estado === 'en_curso' && $periods->count() === $year->num_periodos
                && $periods->every(fn (Periodo $period) => $period->estado === 'cerrado'), 422,
                'El año debe estar en curso y todos los períodos reales cerrados.');
            $enrollment = Matricula::with('grupo.grado', 'estudiante')->where('ano_lectivo_id', $year->id)
                ->where('grupo_id', $group->id)->where('estado', 'activa')->lockForUpdate()->findOrFail($matricula);
            abort_unless($this->resources($group, $scope)->contains('id', $resourceId), 422,
                'La materia o área no pertenece al currículo de este grupo.');
            $config = app(SieeConfiguration::class)->resolve($year);
            abort_unless($config['modo_anual'] === 'MANUAL' && ($scope !== 'area' || $config['usar_areas']), 422,
                'El SIEE de este año no habilita esta definitiva anual manual.');
            $report = $book->report($enrollment, false);
            $unit = collect($scope === 'area' ? $report['areas'] : $report['asignaturas'])
                ->firstWhere($scope.'_id', $resourceId);
            abort_unless($unit && count($unit['periodos']) === $year->num_periodos
                && collect($unit['periodos'])->every(fn ($result) => ($result['estado'] ?? null) === 'calculado'), 422,
                'Completa primero los resultados de todos los períodos reales de esta materia o área.');
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
                abort_if(isset($data['escala_opcion_token']), 422, 'Esta definitiva usa una escala numérica.');
            }
            if ($manualValue !== null) {
                $value = BigDecimal::of((string) $manualValue);
                abort_if($value->isLessThan((string) $config['valor_min'])
                    || $value->isGreaterThan((string) $config['valor_max']), 422,
                    'El resultado está fuera de la escala configurada.');
            }
            $key = ['matricula_id' => $enrollment->id, $scope.'_id' => $resourceId];
            $previous = DB::table('resultados_manuales_anuales')->where($key)->lockForUpdate()->first();
            abort_unless((int) ($previous->version ?? 0) === (int) $data['version'], 409,
                'Otra persona modificó este resultado. Recarga la planilla.');
            abort_if($manualValue === null && (! $previous || $previous->estado !== 'vigente'), 422,
                'No hay un resultado manual vigente para anular.');
            $values = ['valor' => $manualValue, 'estado' => $manualValue === null ? 'anulado' : 'vigente',
                'motivo' => $reason, 'version' => (int) ($previous->version ?? 0) + 1,
                'updated_by' => $request->user()->id, 'updated_at' => now()];
            if ($previous) {
                DB::table('resultados_manuales_anuales')->where('id', $previous->id)->update($values);
                $id = $previous->id;
            } else {
                $id = DB::table('resultados_manuales_anuales')->insertGetId([...$key, ...$values, 'created_at' => now()]);
            }
            AuditLogger::tenant($request->user(), $manualValue === null ? 'ANULAR' : ($previous ? 'UPDATE' : 'CREATE'),
                'resultado_manual_anual', (string) $id, $previous ? (array) $previous : null,
                [...$key, ...$values], $reason);

            return $values['version'];
        });

        return response()->json(['data' => ['version' => $version]]);
    }

    private function resources(Grupo $group, string $scope)
    {
        $query = DB::table('materias_curriculares as mc')
            ->where('mc.ano_lectivo_id', $group->ano_lectivo_id)->where('mc.grado_id', $group->grado_id);
        if ($scope === 'area') {
            return $query->join('areas as recurso', 'recurso.id', '=', 'mc.area_id')
                ->select('recurso.id', 'recurso.nombre')->distinct()->orderBy('recurso.nombre')->get();
        }

        return $query->join('asignaciones_docentes as ad', function ($join) use ($group) {
            $join->on('ad.materia_id', '=', 'mc.materia_id')
                ->where('ad.grupo_id', '=', $group->id)
                ->where('ad.ano_lectivo_id', '=', $group->ano_lectivo_id)
                ->whereNull('ad.deleted_at');
        })->join('materias as recurso', 'recurso.id', '=', 'mc.materia_id')
            ->select('recurso.id', 'recurso.nombre')->distinct()->orderBy('recurso.nombre')->get();
    }
}
