<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Grado;
use App\Models\Academico\Materia;
use App\Models\Academico\MetodoAprobacion;
use App\Services\GradeCalculationService;
use App\Services\SieeConfiguration;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SieeController extends Controller
{
    use PaginatesRequests;

    public function show(int $id): JsonResponse
    {
        $year = AnoLectivo::findOrFail($id);
        $curriculo = $this->curriculoQuery($id, request())->paginate($this->resolvePerPage(request()), ['*'], 'page', $this->resolvePage(request()));
        $grados = Grado::where('ano_lectivo_id', $id)->where('estado', 'activo')->orderBy('nombre')->orderBy('id')
            ->paginate(50, ['id', 'nombre'], 'grados_page', 1);
        $materias = Materia::where('ano_lectivo_id', $id)->where('estado', 'activo')->orderBy('nombre')->orderBy('id')
            ->paginate(50, ['id', 'nombre'], 'materias_page', 1);
        $areas = Area::where('ano_lectivo_id', $id)->where('estado', 'activo')->orderBy('nombre')->orderBy('id')
            ->paginate(50, ['id', 'nombre'], 'areas_page', 1);

        return response()->json(['data' => [
            'editable' => ! $year->estaCerrado() && ! $year->periodos()->where('estado', 'cerrado')->exists(),
            'configuracion' => array_replace(SieeConfiguration::DEFAULTS, $year->siee ?? []),
            'escalas' => EscalaValorativa::where('ano_lectivo_id', $id)->get(),
            'metodos' => MetodoAprobacion::where('ano_lectivo_id', $id)->get(),
            'curriculo' => $curriculo->items(),
            'pagination' => ['curriculo' => $this->paginationMeta($curriculo),
                'grados' => $this->paginationMeta($grados), 'materias' => $this->paginationMeta($materias),
                'areas' => $this->paginationMeta($areas)],
            'grados' => $grados->items(),
            'materias' => $materias->items(),
            'areas' => $areas->items(),
        ]]);
    }

    public function curriculoIndex(Request $request, int $id): JsonResponse
    {
        AnoLectivo::findOrFail($id);

        return $this->paginatedResponse($this->curriculoQuery($id, $request)
            ->paginate($this->resolvePerPage($request), ['*'], 'page', $this->resolvePage($request)));
    }

    private function curriculoQuery(int $yearId, Request $request): \Illuminate\Database\Query\Builder
    {
        return DB::table('materias_curriculares as mc')
            ->leftJoin('materias as m', 'm.id', '=', 'mc.materia_id')
            ->leftJoin('grados as g', 'g.id', '=', 'mc.grado_id')
            ->leftJoin('areas as a', 'a.id', '=', 'mc.area_id')
            ->where('mc.ano_lectivo_id', $yearId)
            ->when($request->query('grado_id') !== null, fn ($q) => $q->where('mc.grado_id', (int) $request->query('grado_id')))
            ->when($request->query('materia_id') !== null, fn ($q) => $q->where('mc.materia_id', (int) $request->query('materia_id')))
            ->when($request->query('area_id') !== null, fn ($q) => $q->where('mc.area_id', (int) $request->query('area_id')))
            ->when($request->query('search') !== null && $request->query('search') !== '', function ($q) use ($request) {
                $term = '%'.trim((string) $request->query('search')).'%';
                $q->where(fn ($where) => $where->where('m.nombre', 'like', $term)
                    ->orWhere('g.nombre', 'like', $term)->orWhere('a.nombre', 'like', $term));
            })
            ->select('mc.*', 'g.nombre as grado_nombre', 'm.nombre as materia_nombre', 'a.nombre as area_nombre')
            ->orderBy('mc.grado_id')->orderBy('mc.materia_id')->orderBy('mc.id');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'usar_areas' => ['required', 'boolean'],
            'modo_area' => ['required', Rule::in([...GradeCalculationService::MODES, 'DISABLED'])],
            'modo_asignatura' => ['required', Rule::in(GradeCalculationService::MODES)],
            'modo_anual' => ['required', Rule::in(GradeCalculationService::MODES)],
            'redondeo' => ['required', Rule::in(['HALF_UP', 'TRUNCATE'])],
            'precision_calculo' => ['required', 'integer', 'min:4', 'max:12'],
            'recuperacion' => ['required', Rule::in(['REPLACE', 'AVERAGE', 'MAX_PASSING_GRADE', 'MANUAL'])],
            'mostrar_final' => ['required', 'boolean'], 'etiqueta_final' => ['required', 'string', 'max:60'],
            'escala_id' => ['required', 'integer', Rule::exists('escalas_valorativas', 'id')->where('ano_lectivo_id', $id)->whereNull('deleted_at')],
            'metodo_id' => ['required', 'integer', Rule::exists('metodos_aprobacion', 'id')->where('ano_lectivo_id', $id)->whereNull('deleted_at')],
        ]);
        DB::transaction(function () use ($id, $data, $request) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($id);
            abort_if($year->periodo_sumatorio && $data['modo_anual'] === 'MANUAL', 422,
                'El período sumatorio necesita un cálculo anual simple o ponderado. Cambia el método anual o desactiva el período sumatorio.');
            abort_if($year->estaCerrado(), 422, 'El año está cerrado; su SIEE es inmutable.');
            abort_if($year->periodos()->where('estado', 'cerrado')->exists(), 422, 'Hay períodos cerrados; no se puede alterar su configuración SIEE.');
            abort_if($data['usar_areas'] && $data['modo_area'] === 'DISABLED', 422, 'Selecciona un cálculo de área o desactiva el uso de áreas.');
            $scale = EscalaValorativa::findOrFail($data['escala_id']);
            $method = MetodoAprobacion::findOrFail($data['metodo_id']);
            abort_unless($scale->tipo === 'numerica' && $scale->valor_min < $scale->valor_max && $method->nota_minima >= $scale->valor_min && $method->nota_minima <= $scale->valor_max, 422, 'La nota aprobatoria debe pertenecer a la escala numérica.');
            $previous = $year->siee;
            $year->update(['siee' => $data]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'siee', (string) $id, $previous, $data);
        });

        return $this->show($id);
    }

    public function curriculo(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'grado_id' => ['required', 'integer', Rule::exists('grados', 'id')->where('ano_lectivo_id', $id)->whereNull('deleted_at')],
            'materia_id' => ['required', 'integer', Rule::exists('materias', 'id')->where('ano_lectivo_id', $id)->whereNull('deleted_at')],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')->where('ano_lectivo_id', $id)->whereNull('deleted_at')],
            'peso_area' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
        ]);
        DB::transaction(function () use ($id, $data, $request) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($id);
            abort_if($year->estaCerrado() || $year->periodos()->where('estado', 'cerrado')->exists(), 422, 'No se puede cambiar el currículo con períodos cerrados.');
            $key = ['ano_lectivo_id' => $id, 'grado_id' => $data['grado_id'], 'materia_id' => $data['materia_id']];
            $previous = DB::table('materias_curriculares')->where($key)->first();
            DB::table('materias_curriculares')->updateOrInsert($key, [...$data, 'updated_at' => now(), ...($previous ? [] : ['created_at' => now()])]);
            AuditLogger::tenant($request->user(), $previous ? 'UPDATE' : 'CREATE', 'materia_curricular', $id.':'.$data['grado_id'].':'.$data['materia_id'], $previous ? (array) $previous : null, $data);
        });

        return $this->show($id);
    }
}
