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
use App\Support\OpaqueUrlToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SieeController extends Controller
{
    use PaginatesRequests;

    public function show(int $id): JsonResponse
    {
        $year = AnoLectivo::findOrFail($id);
        $curriculo = $this->paginateAcademic($this->curriculoQuery($id, request()), request());
        $grados = Grado::where('ano_lectivo_id', $id)->where('estado', 'activo')->orderBy('nombre')->orderBy('id')
            ->paginate(50, ['id', 'nombre', 'nivel_id', 'estado'], 'grados_page', 1);
        $materias = Materia::with('area:id,nombre')->where('ano_lectivo_id', $id)->where('estado', 'activo')->orderBy('nombre')->orderBy('id')
            ->paginate(50, ['id', 'nombre', 'nivel_id', 'area_id', 'estado'], 'materias_page', 1);
        $areas = Area::where('ano_lectivo_id', $id)->where('estado', 'activo')->orderBy('nombre')->orderBy('id')
            ->paginate(50, ['id', 'nombre'], 'areas_page', 1);
        $configuration = array_replace(SieeConfiguration::DEFAULTS, $year->siee ?? []);
        $scale = EscalaValorativa::where('ano_lectivo_id', $id)->find($configuration['escala_id']);
        $method = MetodoAprobacion::where('ano_lectivo_id', $id)->find($configuration['metodo_id']);
        unset($configuration['escala_id'], $configuration['metodo_id']);
        $configuration['escala_token'] = $scale ? OpaqueUrlToken::for('escala-valorativa', $scale->id) : null;
        $configuration['metodo_token'] = $method ? OpaqueUrlToken::for('metodo-aprobacion', $method->id) : null;

        return response()->json(['data' => [
            'editable' => ! $year->estaCerrado() && ! $year->periodos()->where('estado', 'cerrado')->exists(),
            'curriculo_editable' => $this->curriculoEditable($year),
            'configuracion' => $configuration,
            'escalas' => EscalaValorativa::where('ano_lectivo_id', $id)->get()->map(fn (EscalaValorativa $item) => [
                'url_token' => OpaqueUrlToken::for('escala-valorativa', $item->id),
                'nombre' => $item->nombre, 'tipo' => $item->tipo, 'valor_min' => $item->valor_min,
                'valor_max' => $item->valor_max, 'decimales' => $item->decimales,
                'nivel_educativo' => $item->nivel_educativo,
            ]),
            'metodos' => MetodoAprobacion::where('ano_lectivo_id', $id)->get()->map(fn (MetodoAprobacion $item) => [
                'url_token' => OpaqueUrlToken::for('metodo-aprobacion', $item->id),
                'calculo_nota' => $item->calculo_nota, 'nota_minima' => $item->nota_minima,
                'ambito' => $item->ambito,
            ]),
            'curriculo' => $curriculo->through(fn ($row) => $this->presentCurriculum($row))->items(),
            'pagination' => ['curriculo' => $this->paginationMeta($curriculo),
                'grados' => $this->paginationMeta($grados), 'materias' => $this->paginationMeta($materias),
                'areas' => $this->paginationMeta($areas)],
            'grados' => collect($grados->items())->map(fn (Grado $item) => $this->presentGrade($item)),
            'materias' => collect($materias->items())->map(fn (Materia $item) => $this->presentSubject($item)),
            'areas' => collect($areas->items())->map(fn (Area $item) => $this->presentArea($item)),
        ]]);
    }

    public function curriculoIndex(Request $request, int $id): JsonResponse
    {
        AnoLectivo::findOrFail($id);

        return $this->paginatedResponse($this->paginateAcademic($this->curriculoQuery($id, $request), $request)
            ->through(fn ($row) => $this->presentCurriculum($row)));
    }

    private function curriculoQuery(int $yearId, Request $request): \Illuminate\Database\Query\Builder
    {
        $filters = $request->validate([
            'grado_id' => ['prohibited'], 'materia_id' => ['prohibited'], 'area_id' => ['prohibited'],
            'grado_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'materia_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'area_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        foreach (['grado' => Grado::class, 'materia' => Materia::class, 'area' => Area::class] as $name => $model) {
            if (isset($filters[$name.'_token'])) {
                $filters[$name.'_id'] = $this->resolveSelector($name, $filters[$name.'_token'],
                    $model::where('ano_lectivo_id', $yearId), $name.'_token')->getKey();
            }
        }

        return DB::table('materias_curriculares as mc')
            ->leftJoin('materias as m', 'm.id', '=', 'mc.materia_id')
            ->leftJoin('grados as g', 'g.id', '=', 'mc.grado_id')
            ->leftJoin('areas as a', 'a.id', '=', 'mc.area_id')
            ->where('mc.ano_lectivo_id', $yearId)
            ->when(isset($filters['grado_id']), fn ($q) => $q->where('mc.grado_id', $filters['grado_id']))
            ->when(isset($filters['materia_id']), fn ($q) => $q->where('mc.materia_id', $filters['materia_id']))
            ->when(isset($filters['area_id']), fn ($q) => $q->where('mc.area_id', $filters['area_id']))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $term = '%'.trim($filters['search']).'%';
                $q->where(fn ($where) => $where->where('m.nombre', 'like', $term)
                    ->orWhere('g.nombre', 'like', $term)->orWhere('a.nombre', 'like', $term));
            })
            ->select('mc.grado_id', 'mc.materia_id', 'mc.area_id', 'mc.peso_area',
                'g.nombre as grado_nombre', 'm.nombre as materia_nombre', 'a.nombre as area_nombre')
            ->orderBy('mc.grado_id')->orderBy('mc.materia_id')->orderBy('mc.id');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'escala_id' => ['prohibited'], 'metodo_id' => ['prohibited'],
            'usar_areas' => ['required', 'boolean'],
            'modo_area' => ['required', Rule::in([...GradeCalculationService::MODES, 'DISABLED'])],
            'modo_asignatura' => ['required', Rule::in(GradeCalculationService::MODES)],
            'modo_anual' => ['required', Rule::in(GradeCalculationService::MODES)],
            'redondeo' => ['required', Rule::in(['HALF_UP', 'TRUNCATE'])],
            'precision_calculo' => ['required', 'integer', 'min:4', 'max:12'],
            'recuperacion' => ['required', Rule::in(['REPLACE', 'AVERAGE', 'MAX_PASSING_GRADE', 'MANUAL'])],
            'mostrar_final' => ['required', 'boolean'], 'etiqueta_final' => ['required', 'string', 'max:60'],
            'escala_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'metodo_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
        ]);
        DB::transaction(function () use ($id, $data, $request) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($id);
            $scale = $this->resolveSelector('escala-valorativa', $data['escala_token'],
                EscalaValorativa::where('ano_lectivo_id', $id), 'escala_token');
            $method = $this->resolveSelector('metodo-aprobacion', $data['metodo_token'],
                MetodoAprobacion::where('ano_lectivo_id', $id), 'metodo_token');
            abort_if($year->periodo_sumatorio && $data['modo_anual'] === 'MANUAL', 422,
                'El período sumatorio necesita un cálculo anual simple o ponderado. Cambia el método anual o desactiva el período sumatorio.');
            abort_if($year->estaCerrado(), 422, 'El año está cerrado; su SIEE es inmutable.');
            abort_if($year->periodos()->where('estado', 'cerrado')->exists(), 422, 'Hay períodos cerrados; no se puede alterar su configuración SIEE.');
            abort_if($data['usar_areas'] && $data['modo_area'] === 'DISABLED', 422, 'Selecciona un cálculo de área o desactiva el uso de áreas.');
            abort_unless($scale->tipo === 'numerica' && $scale->valor_min < $scale->valor_max && $method->nota_minima >= $scale->valor_min && $method->nota_minima <= $scale->valor_max, 422, 'La nota aprobatoria debe pertenecer a la escala numérica.');
            $previous = $year->siee;
            unset($data['escala_token'], $data['metodo_token']);
            $data['escala_id'] = $scale->id;
            $data['metodo_id'] = $method->id;
            $year->update(['siee' => $data]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'siee', (string) $id, $previous, $data);
        });

        return $this->show($id);
    }

    public function curriculo(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'grado_id' => ['prohibited'], 'materia_id' => ['prohibited'], 'area_id' => ['prohibited'],
            'grado_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'materia_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'area_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'peso_area' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:100',
                'regex:/^(?:100(?:\.0{1,4})?|(?:0|[1-9]\d?)(?:\.\d{1,4})?)$/'],
        ]);
        DB::transaction(function () use ($id, $data, $request) {
            $year = AnoLectivo::lockForUpdate()->findOrFail($id);
            abort_unless($this->curriculoEditable($year), 422,
                'No se puede cambiar el currículo: el año está cerrado o hay calificaciones en períodos cerrados.');
            $grade = $this->resolveSelector('grado', $data['grado_token'],
                Grado::where('ano_lectivo_id', $id)->where('estado', 'activo'), 'grado_token');
            $subject = $this->resolveSelector('materia', $data['materia_token'],
                Materia::where('ano_lectivo_id', $id)->where('estado', 'activo'), 'materia_token');
            abort_if($subject->nivel_id !== null && $subject->nivel_id !== $grade->nivel_id, 422,
                'La materia no corresponde al nivel educativo del grado.');
            $area = isset($data['area_token']) ? $this->resolveSelector('area', $data['area_token'],
                Area::where('ano_lectivo_id', $id)->where('estado', 'activo'), 'area_token') : null;
            abort_if($area && $area->id !== $subject->area_id, 422,
                'El área debe ser la configurada para la materia en el plan de estudios.');
            unset($data['grado_token'], $data['materia_token'], $data['area_token']);
            $data['grado_id'] = $grade->id;
            $data['materia_id'] = $subject->id;
            $data['area_id'] = $subject->area_id;
            $key = ['ano_lectivo_id' => $id, 'grado_id' => $data['grado_id'], 'materia_id' => $data['materia_id']];
            $previous = DB::table('materias_curriculares')->where($key)->first();
            DB::table('materias_curriculares')->updateOrInsert($key, [...$data, 'updated_at' => now(), ...($previous ? [] : ['created_at' => now()])]);
            AuditLogger::tenant($request->user(), $previous ? 'UPDATE' : 'CREATE', 'materia_curricular', $id.':'.$data['grado_id'].':'.$data['materia_id'], $previous ? (array) $previous : null, $data);
        });

        return $this->show($id);
    }

    private function resolveSelector(string $resource, string $token, Builder $scope, string $field): Model
    {
        $model = OpaqueUrlToken::find($resource, $token, $scope);
        if (! $model) {
            throw ValidationException::withMessages([$field => 'La selección no existe en este colegio o año lectivo.']);
        }

        return $model;
    }

    private function presentGrade(Grado $grade): array
    {
        return [
            'url_token' => OpaqueUrlToken::for('grado', $grade->id),
            'nombre' => $grade->nombre,
            'nivel_token' => $grade->nivel_id ? OpaqueUrlToken::for('nivel', $grade->nivel_id) : null,
            'estado' => $grade->estado,
        ];
    }

    private function presentSubject(Materia $subject): array
    {
        return [
            'url_token' => OpaqueUrlToken::for('materia', $subject->id),
            'nombre' => $subject->nombre,
            'nivel_token' => $subject->nivel_id ? OpaqueUrlToken::for('nivel', $subject->nivel_id) : null,
            'area_token' => $subject->area_id ? OpaqueUrlToken::for('area', $subject->area_id) : null,
            'area' => $subject->area ? $this->presentArea($subject->area) : null,
            'estado' => $subject->estado,
        ];
    }

    private function presentArea(Area $area): array
    {
        return ['url_token' => OpaqueUrlToken::for('area', $area->id), 'nombre' => $area->nombre];
    }

    private function presentCurriculum(object $row): array
    {
        return [
            'grado_token' => OpaqueUrlToken::for('grado', $row->grado_id),
            'materia_token' => OpaqueUrlToken::for('materia', $row->materia_id),
            'area_token' => $row->area_id ? OpaqueUrlToken::for('area', $row->area_id) : null,
            'peso_area' => $row->peso_area,
            'grado_nombre' => $row->grado_nombre,
            'materia_nombre' => $row->materia_nombre,
            'area_nombre' => $row->area_nombre,
        ];
    }

    private function curriculoEditable(AnoLectivo $year): bool
    {
        if ($year->estaCerrado()) {
            return false;
        }

        return ! DB::table('calificaciones as nota')
            ->join('actividades_evaluacion as actividad', 'actividad.id', '=', 'nota.actividad_id')
            ->join('componentes_evaluacion as componente', 'componente.id', '=', 'actividad.componente_id')
            ->join('periodos as periodo', 'periodo.id', '=', 'componente.periodo_id')
            ->where('periodo.ano_lectivo_id', $year->id)
            ->where('periodo.estado', 'cerrado')
            ->exists();
    }
}
