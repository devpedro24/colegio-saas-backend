<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Aula;
use App\Models\Academico\AulaAdjunto;
use App\Models\Academico\AulaEntrega;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;
use App\Models\Academico\Calificacion;
use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Services\AcademicPlanAccess;
use App\Services\AulaAccess;
use App\Services\AulaGradebookService;
use App\Services\EscalaVisualService;
use App\Services\SieeConfiguration;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken as Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Brick\Math\BigDecimal;

final class AulaController extends Controller
{
    public function __construct(private readonly AulaAccess $access, private readonly AcademicPlanAccess $plan) {}

    public function catalogo(Request $request): JsonResponse
    {
        $this->plan->requireAula();
        $user = $request->user();
        $all = $user->can('aula.ver_todas');
        $assigned = $user->can('aula.ver_asignadas');
        $own = $user->can('aula.ver_propias');
        abort_unless($all || $assigned || $own, 403);
        $years = AnoLectivo::orderByDesc('fecha_inicio')->get(['id', 'nombre', 'estado']);
        $year = $request->filled('ano_token')
            ? Token::find('ano-lectivo', $request->query('ano_token'), AnoLectivo::query())
            : ($years->firstWhere('estado', 'en_curso') ?? $years->first());
        abort_if($request->filled('ano_token') && ! $year, 404);
        $cards = collect();
        $groups = collect();
        $selectedGroup = null;
        $requiresGroup = $all;
        if ($year) {
            $groupQuery = DB::table('grupos as g')
                ->join('grados as gr', 'gr.id', '=', 'g.grado_id')
                ->where('g.ano_lectivo_id', $year->id)->whereNull('g.deleted_at')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('materias_curriculares as mc')
                    ->whereColumn('mc.grado_id', 'g.grado_id')->whereColumn('mc.ano_lectivo_id', 'g.ano_lectivo_id'));
            if (! $all) {
                $groupQuery->where(function ($scope) use ($user, $assigned, $own) {
                    if ($assigned) $scope->orWhereExists(fn ($q) => $q->selectRaw('1')->from('asignaciones_docentes as ad')
                        ->whereColumn('ad.grupo_id', 'g.id')->whereColumn('ad.ano_lectivo_id', 'g.ano_lectivo_id')
                        ->where('ad.docente_id', $user->id)->whereNull('ad.deleted_at'));
                    if ($own) $scope->orWhereExists(fn ($q) => $q->selectRaw('1')->from('matriculas as ma')
                        ->whereColumn('ma.grupo_id', 'g.id')->whereColumn('ma.ano_lectivo_id', 'g.ano_lectivo_id')
                        ->where('ma.estudiante_id', $user->id)->where('ma.estado', 'activa'));
                });
            }
            $groupRows = $groupQuery->orderBy('gr.nombre')->orderBy('g.nombre')
                ->get(['g.id', 'g.nombre', 'gr.nombre as grado']);
            $groups = $groupRows->map(fn ($row) => [
                'token' => Token::for('grupo', $row->id), 'nombre' => $row->nombre,
                'grado' => $row->grado, 'etiqueta' => $row->grado.' / '.$row->nombre,
            ]);
            $requiresGroup = $all || $groupRows->count() > 1;
            if ($request->filled('grupo_token')) {
                $group = Token::find('grupo', $request->query('grupo_token'), Grupo::where('ano_lectivo_id', $year->id));
                abort_unless($group && $groupRows->contains('id', $group->id), 404);
                $selectedGroup = $group->id;
            } elseif (! $requiresGroup) {
                $selectedGroup = $groupRows->first()?->id;
            }
        }
        if ($year && $selectedGroup) {
            $query = DB::table('grupos as g')
                ->join('materias_curriculares as mc', fn ($join) => $join->on('mc.grado_id', '=', 'g.grado_id')->on('mc.ano_lectivo_id', '=', 'g.ano_lectivo_id'))
                ->join('materias as m', fn ($join) => $join->on('m.id', '=', 'mc.materia_id')->on('m.ano_lectivo_id', '=', 'g.ano_lectivo_id'))
                ->leftJoin('aulas as a', fn ($join) => $join->on('a.grupo_id', '=', 'g.id')->on('a.materia_id', '=', 'm.id'))
                ->leftJoin('asignaciones_docentes as ad', fn ($join) => $join->on('ad.grupo_id', '=', 'g.id')->on('ad.materia_id', '=', 'm.id')->whereNull('ad.deleted_at'))
                ->leftJoin('users as d', 'd.id', '=', 'ad.docente_id')
                ->where('g.ano_lectivo_id', $year->id)->where('g.id', $selectedGroup)
                ->whereNull('g.deleted_at')->whereNull('m.deleted_at');
            if (! $all) {
                $query->where(function ($scope) use ($user, $assigned, $own) {
                    if ($assigned) $scope->orWhere('ad.docente_id', $user->id);
                    if ($own) $scope->orWhereExists(fn ($q) => $q->selectRaw('1')->from('matriculas as ma')
                        ->whereColumn('ma.grupo_id', 'g.id')->whereColumn('ma.ano_lectivo_id', 'g.ano_lectivo_id')
                        ->where('ma.estudiante_id', $user->id)->where('ma.estado', 'activa'));
                });
            }
            $rows = $query->select('g.id as grupo_id', 'g.nombre as grupo', 'm.id as materia_id', 'm.nombre as materia',
                'a.id as aula_id', 'a.portada_token', 'd.name as docente')->distinct()->orderBy('m.nombre')->get();
            $groupLabel = $groups->firstWhere('token', Token::for('grupo', $selectedGroup))['etiqueta'] ?? null;
            $cards = $rows->map(fn ($row) => [
                'aula_token' => $row->aula_id ? Token::for('aula', $row->aula_id) : null,
                'grupo_token' => Token::for('grupo', $row->grupo_id), 'grupo' => $groupLabel ?: $row->grupo,
                'materia_token' => Token::for('materia', $row->materia_id), 'materia' => $row->materia,
                'docente' => $row->docente ?: 'Sin asignar', 'portada_token' => $row->portada_token,
            ]);
        }

        return response()->json(['data' => [
            'anos' => $years->map(fn ($item) => ['token' => Token::for('ano-lectivo', $item->id), 'nombre' => $item->nombre, 'estado' => $item->estado]),
            'ano_token' => $year ? Token::for('ano-lectivo', $year->id) : null,
            'grupos' => $groups, 'aulas' => $cards, 'requiere_grupo' => $requiresGroup,
            'grupo_seleccionado' => $selectedGroup ? Token::for('grupo', $selectedGroup) : null,
            'puede_gestionar' => $user->can('aula.recursos.gestionar'),
        ]]);
    }

    public function crear(Request $request): JsonResponse
    {
        $this->plan->requireAula();
        $data = $request->validate(['grupo_token' => ['required', 'string'], 'materia_token' => ['required', 'string']]);
        $group = Token::find('grupo', $data['grupo_token'], Grupo::query());
        $subject = Token::find('materia', $data['materia_token'], Materia::query());
        abort_unless($group && $subject && $group->ano_lectivo_id === $subject->ano_lectivo_id, 404);
        abort_unless(DB::table('materias_curriculares')->where('ano_lectivo_id', $group->ano_lectivo_id)
            ->where('grado_id', $group->grado_id)->where('materia_id', $subject->id)->exists(), 422,
            'La asignatura no está inscrita en el currículo del grado.');
        $candidate = new Aula(['ano_lectivo_id' => $group->ano_lectivo_id, 'grupo_id' => $group->id, 'materia_id' => $subject->id]);
        $this->access->manages($request->user(), $candidate);
        $aula = Aula::firstOrCreate(['grupo_id' => $group->id, 'materia_id' => $subject->id],
            ['ano_lectivo_id' => $group->ano_lectivo_id]);
        if ($aula->wasRecentlyCreated) AuditLogger::tenant($request->user(), 'CREATE', 'aula', (string) $aula->id, null, $aula->toArray());

        return response()->json(['data' => ['token' => Token::for('aula', $aula->id)]], 201);
    }

    public function ver(Request $request, string $aulaToken): JsonResponse
    {
        $aula = $this->aula($aulaToken);
        $this->access->view($request->user(), $aula);
        $student = $this->access->isStudent($request->user(), $aula);
        $sections = AulaSeccion::with(['periodo', 'recursos'])->where('aula_id', $aula->id)->orderBy('orden')->orderBy('id')->get()
            ->filter(fn ($section) => ! $student || $section->visible_estudiantes)
            ->map(fn ($section) => [
                'token' => Token::for('aula-seccion', $section->id), 'titulo' => $section->titulo,
                'periodo' => $section->periodo->nombre, 'periodo_token' => Token::for('periodo', $section->periodo_id),
                'preinforme_token' => $section->preinforme_id ? Token::for('preinforme', $section->preinforme_id) : null,
                'visible_estudiantes' => $section->visible_estudiantes, 'orden' => $section->orden,
                'recursos' => $section->recursos->filter(fn ($resource) => ! $student || $this->access->visible($resource))
                    ->map(fn ($resource) => $this->resourceData($resource, $student))->values(),
            ])->values();
        $periods = Periodo::where('ano_lectivo_id', $aula->ano_lectivo_id)->orderBy('orden')->get();

        return response()->json(['data' => [
            'token' => Token::for('aula', $aula->id), 'grupo' => $aula->grupo->nombre, 'materia' => $aula->materia->nombre,
            'portada_token' => $aula->portada_token, 'estudiante' => $student,
            'puede_gestionar' => ! $student && $this->access->canManage($request->user(), $aula),
            'periodos' => $periods->map(fn ($p) => ['token' => Token::for('periodo', $p->id), 'nombre' => $p->nombre,
                'estado' => $p->estado, 'preinformes' => Preinforme::where('periodo_id', $p->id)->orderBy('orden')->get()
                    ->map(fn ($pre) => ['token' => Token::for('preinforme', $pre->id), 'nombre' => $pre->nombre])]),
            'secciones' => $sections,
        ]]);
    }

    public function crearSeccion(Request $request, string $aulaToken): JsonResponse
    {
        $aula = $this->aula($aulaToken);
        $this->access->manages($request->user(), $aula);
        $data = $request->validate(['periodo_token' => ['required', 'string'], 'preinforme_token' => ['nullable', 'string'],
            'titulo' => ['required', 'string', 'max:160']]);
        $period = Token::find('periodo', $data['periodo_token'], Periodo::where('ano_lectivo_id', $aula->ano_lectivo_id));
        abort_unless($period, 404);
        abort_if($period->estaCerrado(), 422, 'El período está cerrado.');
        $pre = ! empty($data['preinforme_token']) ? Token::find('preinforme', $data['preinforme_token'], Preinforme::where('periodo_id', $period->id)) : null;
        abort_if(! empty($data['preinforme_token']) && ! $pre, 404);
        $section = AulaSeccion::create(['aula_id' => $aula->id, 'periodo_id' => $period->id, 'preinforme_id' => $pre?->id,
            'titulo' => trim($data['titulo']), 'orden' => (int) AulaSeccion::where('aula_id', $aula->id)->max('orden') + 1,
            'autor_id' => $request->user()->id]);
        AuditLogger::tenant($request->user(), 'CREATE', 'aula_seccion', (string) $section->id, null, $section->toArray());

        return response()->json(['data' => ['token' => Token::for('aula-seccion', $section->id)]], 201);
    }

    public function editarSeccion(Request $request, string $token): JsonResponse
    {
        $section = Token::find('aula-seccion', $token, AulaSeccion::query());
        abort_unless($section, 404);
        $this->access->manages($request->user(), $section->aula);
        abort_if($section->periodo->estaCerrado(), 422, 'El período está cerrado.');
        $data = $request->validate(['titulo' => ['sometimes', 'string', 'max:160'], 'orden' => ['sometimes', 'integer', 'min:0'],
            'visible_estudiantes' => ['sometimes', 'boolean']]);
        $before = $section->toArray();
        $section->update($data);
        AuditLogger::tenant($request->user(), 'UPDATE', 'aula_seccion', (string) $section->id, $before, $section->toArray());

        return response()->json(['data' => ['token' => $token]]);
    }

    public function crearRecurso(Request $request, string $sectionToken): JsonResponse
    {
        $section = Token::find('aula-seccion', $sectionToken, AulaSeccion::query());
        abort_unless($section, 404);
        $this->access->manages($request->user(), $section->aula,
            $request->input('tipo') === 'cuestionario' ? 'aula.evaluaciones.gestionar' : 'aula.recursos.gestionar');
        abort_if($section->periodo->estaCerrado(), 422, 'El período está cerrado.');
        $data = $this->resourceInput($request);
        $resource = DB::transaction(function () use ($data, $section, $request) {
            $resource = AulaRecurso::create([...$this->resourceFields($data), 'seccion_id' => $section->id,
                'orden' => (int) AulaRecurso::where('seccion_id', $section->id)->max('orden') + 1,
                'autor_id' => $request->user()->id, 'version' => 1]);
            if ($resource->llevar_planilla) app(AulaGradebookService::class)->link($resource, $request->user());
            AuditLogger::tenant($request->user(), 'CREATE', 'aula_recurso', (string) $resource->id, null, $resource->toArray());
            return $resource;
        });

        return response()->json(['data' => ['token' => Token::for('aula-recurso', $resource->id)]], 201);
    }

    public function editarRecurso(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource, 404);
        $this->access->manages($request->user(), $resource->seccion->aula,
            ($resource->tipo === 'cuestionario' || $request->input('tipo') === 'cuestionario')
                ? 'aula.evaluaciones.gestionar' : 'aula.recursos.gestionar');
        abort_if($resource->seccion->periodo->estaCerrado(), 422, 'El período está cerrado.');
        $data = $this->resourceInput($request);
        abort_unless((int) $request->input('version') === $resource->version, 409, 'El recurso cambió. Recarga antes de guardar.');
        abort_if($resource->actividad_id && ! ($data['llevar_planilla'] ?? $resource->llevar_planilla), 422,
            'La actividad ya está vinculada a la planilla. Conserva el vínculo y las notas históricas.');
        abort_if($resource->actividad_id && ($data['titulo'] !== $resource->titulo
            || (($data['peso'] ?? null) === null) !== ($resource->peso === null)
            || (($data['peso'] ?? null) !== null && $resource->peso !== null
                && ! BigDecimal::of((string) $data['peso'])->isEqualTo((string) $resource->peso))), 422,
            'La actividad vinculada conserva su nombre y porcentaje para proteger las notas históricas.');
        abort_if($resource->tipo !== $data['tipo'] && ($resource->preguntas()->exists() || $resource->entregas()->exists()), 422,
            'El recurso ya tiene preguntas o entregas y no puede cambiar de tipo.');
        DB::transaction(function () use ($resource, $data, $request): void {
            $before = $resource->toArray();
            $resource->update([...$this->resourceFields($data, $resource), 'version' => $resource->version + 1]);
            if ($resource->llevar_planilla && ! $resource->actividad_id) app(AulaGradebookService::class)->link($resource, $request->user());
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_recurso', (string) $resource->id, $before, $resource->toArray());
        });

        return response()->json(['data' => $this->resourceData($resource)]);
    }

    public function verRecurso(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource, 404);
        $this->access->readResource($request->user(), $resource);
        $student = $this->access->isStudent($request->user(), $resource->seccion->aula);
        $data = $this->resourceData($resource, $student);
        $data['estudiante'] = $student;
        $data['puede_gestionar'] = ! $student && $this->access->canManage($request->user(), $resource->seccion->aula);
        $data['puede_calificar'] = ! $student && $this->access->canManage($request->user(),
            $resource->seccion->aula, 'aula.entregas.calificar');
        $data['puede_evaluar'] = ! $student && $this->access->canManage($request->user(),
            $resource->seccion->aula, 'aula.evaluaciones.gestionar');
        if ($student) {
            $data['puede_interactuar'] = $resource->estado !== 'cerrado'
                && ! $resource->seccion->periodo->estaCerrado()
                && (! $resource->disponible_hasta || now('UTC')->lte($resource->disponible_hasta));
        }
        if (! $student) {
            $assignment = $this->access->assignment($resource->seccion->aula);
            $data['requiere_motivo'] = $assignment
                ? app(\App\Services\GradebookService::class)->requiresReason($request->user(), $assignment) : true;
        }
        if ($resource->calificable) {
            $visual = app(EscalaVisualService::class);
            $data['escala'] = $visual->present($visual->forGroup($resource->seccion->aula->grupo));
        }
        if ($resource->tipo === 'cuestionario' && $student) {
            $data['cantidad_preguntas'] = $resource->preguntas()->count();
        } elseif ($resource->tipo === 'cuestionario') {
            $data['preguntas'] = $resource->preguntas->map(fn ($q) => [
                'token' => Token::for('aula-pregunta', $q->id), 'tipo' => $q->tipo,
                'enunciado' => $q->enunciado, 'opciones' => $q->opciones, 'puntos' => $q->puntos,
                ...($data['puede_evaluar'] ? ['respuesta_correcta' => $q->respuesta_correcta] : []),
            ]);
        }
        if ($resource->tipo === 'tarea' && $student) {
            $enrollment = $this->access->enrollment($request->user(), $resource->seccion->aula);
            $submission = AulaEntrega::where('recurso_id', $resource->id)->where('matricula_id', $enrollment->id)->first();
            $official = $resource->actividad_id ? Calificacion::where('actividad_id', $resource->actividad_id)
                ->where('matricula_id', $enrollment->id)->first() : null;
            $choiceId = $official?->escala_opcion_id ?? $submission?->escala_opcion_id;
            $data['entrega'] = $submission ? ['token' => Token::for('aula-entrega', $submission->id),
                'texto' => $submission->texto, 'estado' => $submission->estado, 'entregada_at' => $submission->entregada_at,
                'nota' => $choiceId ? null : ($official?->valor ?? $submission->nota),
                'valoracion' => $choiceId ? EscalaOpcionController::present(EscalaOpcion::findOrFail($choiceId)) : null,
                'retroalimentacion' => $submission->retroalimentacion,
                'adjuntos' => AulaAdjunto::where('entrega_id', $submission->id)->get()->map(AulaArchivoController::present(...))] : null;
        }

        return response()->json(['data' => $data]);
    }

    public function entregar(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource && $resource->tipo === 'tarea', 404);
        $this->access->readResource($request->user(), $resource);
        abort_unless($request->user()->can('aula.entregas.enviar'), 403);
        abort_if($resource->estado === 'cerrado' || $resource->seccion->periodo->estaCerrado()
            || ($resource->disponible_hasta && now('UTC')->gt($resource->disponible_hasta)), 422,
            'El plazo de interacción de este recurso terminó.');
        $enrollment = $this->access->enrollment($request->user(), $resource->seccion->aula);
        abort_unless($enrollment, 403);
        abort_if($resource->fecha_limite && now('UTC')->gt($resource->fecha_limite)
            && ! ($resource->configuracion['entrega_tardia'] ?? false), 422, 'La fecha límite ya pasó.');
        $data = $request->validate(['texto' => ['nullable', 'string', 'max:20000']]);
        $submission = AulaEntrega::firstOrNew(['recurso_id' => $resource->id, 'matricula_id' => $enrollment->id]);
        abort_if($submission->exists && $submission->nota !== null, 422, 'La entrega ya fue calificada.');
        abort_if($submission->exists && ! ($resource->configuracion['reenvios'] ?? false), 422, 'Ya entregaste esta tarea.');
        $before = $submission->exists ? $submission->toArray() : null;
        $submission->fill(['texto' => $data['texto'] ?? null, 'estado' => 'entregada', 'entregada_at' => now('UTC'),
            'version' => ($submission->version ?? 0) + 1])->save();
        AuditLogger::tenant($request->user(), $before ? 'UPDATE' : 'CREATE', 'aula_entrega', (string) $submission->id, $before, $submission->toArray());

        return response()->json(['data' => ['token' => Token::for('aula-entrega', $submission->id)]], 201);
    }

    public function entregas(Request $request, string $resourceToken): JsonResponse
    {
        $resource = Token::find('aula-recurso', $resourceToken, AulaRecurso::query());
        abort_unless($resource && $resource->tipo === 'tarea', 404);
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.entregas.calificar');
        $official = $resource->actividad_id ? Calificacion::where('actividad_id', $resource->actividad_id)
            ->get()->keyBy('matricula_id') : collect();
        return response()->json(['data' => AulaEntrega::with('matricula.estudiante', 'escalaOpcion')->where('recurso_id', $resource->id)->get()->map(fn ($item) => [
            'token' => Token::for('aula-entrega', $item->id), 'estudiante' => $item->matricula->estudiante->name,
            'texto' => $item->texto, 'estado' => $item->estado,
            'nota' => $official->get($item->matricula_id)?->valor ?? $item->nota,
            'escala_opcion_token' => ($official->get($item->matricula_id)?->escala_opcion_id ?? $item->escala_opcion_id)
                ? Token::for('escala-opcion', $official->get($item->matricula_id)?->escala_opcion_id ?? $item->escala_opcion_id) : null,
            'retroalimentacion' => $item->retroalimentacion, 'entregada_at' => $item->entregada_at,
            'version' => $item->version, 'adjuntos' => AulaAdjunto::where('entrega_id', $item->id)->get()->map(AulaArchivoController::present(...)),
        ])]);
    }

    public function calificar(Request $request, string $token): JsonResponse
    {
        $submission = Token::find('aula-entrega', $token, AulaEntrega::query());
        abort_unless($submission, 404);
        $resource = $submission->recurso;
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.entregas.calificar');
        abort_unless($resource->calificable, 422, 'Esta tarea no es calificable.');
        abort_if($resource->seccion->periodo->estaCerrado(), 422, 'El período está cerrado.');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'nota' => ['nullable', 'numeric'], 'escala_opcion_token' => ['nullable', 'string'],
            'retroalimentacion' => ['nullable', 'string', 'max:10000'], 'motivo' => ['nullable', 'string', 'max:1000']]);
        $assignment = $this->access->assignment($resource->seccion->aula);
        abort_if($assignment && app(\App\Services\GradebookService::class)->requiresReason($request->user(), $assignment)
            && mb_strlen(trim((string) ($data['motivo'] ?? ''))) < 8, 422,
            'La edición delegada requiere un motivo de al menos 8 caracteres.');
        abort_unless($submission->version === $data['version'], 409, 'La entrega cambió. Recarga antes de calificar.');
        $group = $resource->seccion->aula->grupo;
        $scale = app(EscalaVisualService::class)->forGroup($group);
        $choice = ! empty($data['escala_opcion_token']) && $scale
            ? Token::find('escala-opcion', $data['escala_opcion_token'], EscalaOpcion::where('escala_id', $scale->id)) : null;
        abort_if($scale && ! $choice, 422, 'Selecciona una categoría de la escala visual.');
        abort_if(! $scale && (! isset($data['nota']) || ! empty($data['escala_opcion_token'])), 422,
            'Selecciona una nota numérica válida.');
        $value = $choice ? (string) $choice->valor_equivalente : (string) $data['nota'];
        $config = app(SieeConfiguration::class)->resolve(AnoLectivo::findOrFail($group->ano_lectivo_id));
        abort_if(BigDecimal::of($value)->isLessThan((string) $config['valor_min'])
            || BigDecimal::of($value)->isGreaterThan((string) $config['valor_max']), 422, 'La nota está fuera de la escala SIEE.');
        DB::transaction(function () use ($request, $submission, $data, $resource, $value, $choice): void {
            if ($resource->llevar_planilla) app(AulaGradebookService::class)->transfer($resource, $request->user(),
                $submission->matricula_id, $value, $data['escala_opcion_token'] ?? null, $data['motivo'] ?? null);
            $before = $submission->toArray();
            $submission->update(['nota' => $value, 'escala_opcion_id' => $choice?->id,
                'retroalimentacion' => $data['retroalimentacion'] ?? null,
                'calificada_por' => $request->user()->id, 'estado' => 'calificada', 'version' => $submission->version + 1]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_entrega', (string) $submission->id,
                $before, $submission->toArray(), $data['motivo'] ?? null);
        });

        return response()->json(['data' => ['guardado' => true]]);
    }

    private function resourceInput(Request $request): array
    {
        $data = $request->validate([
            'version' => ['sometimes', 'integer', 'min:1'],
            'tipo' => ['required', Rule::in(['texto', 'archivo', 'tarea', 'cuestionario'])],
            'titulo' => ['required', 'string', 'max:160'],
            'contenido' => ['nullable', 'array'], 'contenido.bloques' => ['sometimes', 'array', 'max:100'],
            'contenido.bloques.*.tipo' => ['required_with:contenido.bloques', Rule::in(['titulo', 'parrafo', 'aviso', 'lista', 'tarjeta', 'enlace'])],
            'contenido.bloques.*.texto' => ['nullable', 'string', 'max:10000'],
            'contenido.bloques.*.url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'estado' => ['required', Rule::in(['borrador', 'programado', 'publicado', 'cerrado', 'archivado'])],
            'visible_estudiantes' => ['required', 'boolean'],
            'calificable' => ['required', 'boolean'], 'llevar_planilla' => ['required', 'boolean'],
            'peso' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'disponible_desde' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'disponible_hasta' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'fecha_limite' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'configuracion' => ['nullable', 'array'],
            'configuracion.entrega_tardia' => ['sometimes', 'boolean'],
            'configuracion.reenvios' => ['sometimes', 'boolean'],
            'configuracion.intentos' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'configuracion.duracion_minutos' => ['sometimes', 'integer', 'min:1', 'max:480'],
            'configuracion.preguntas_por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'configuracion.permitir_regresar' => ['sometimes', 'boolean'],
            'configuracion.permitir_editar_respuestas' => ['sometimes', 'boolean'],
            'configuracion.vigilado' => ['sometimes', 'boolean'],
            'configuracion.incidentes_permitidos' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'configuracion.transferencia' => ['sometimes', Rule::in(['confirmar', 'automatica'])],
        ]);
        foreach ($data['contenido']['bloques'] ?? [] as $block) {
            abort_if($block['tipo'] === 'enlace' && (empty($block['url']) || empty(trim($block['texto'] ?? ''))),
                422, 'Cada enlace necesita un nombre y una dirección HTTPS o HTTP válida.');
        }

        return $data;
    }

    private function resourceFields(array $data, ?AulaRecurso $existing = null): array
    {
        abort_if($data['llevar_planilla'] && ! $data['calificable'], 422, 'Para llevar la nota a planilla, el recurso debe ser calificable.');
        abort_if($data['tipo'] === 'texto' && $data['calificable'], 422, 'El contenido informativo no admite nota.');
        $timezone = $existing?->zona_publicacion ?: tenant()->zonaHorariaInstitucional();
        $utc = fn (?string $date) => $date ? Carbon::createFromFormat('Y-m-d\TH:i', $date, $timezone)->utc() : null;
        $from = $utc($data['disponible_desde'] ?? null);
        $until = $utc($data['disponible_hasta'] ?? null);
        abort_if($from && $until && $from->gt($until), 422, 'El cierre debe ser posterior a la apertura.');

        return ['tipo' => $data['tipo'], 'titulo' => trim($data['titulo']),
            'contenido' => ['bloques' => $data['contenido']['bloques'] ?? []],
            'configuracion' => $data['configuracion'] ?? [], 'estado' => $data['estado'],
            'visible_estudiantes' => $data['visible_estudiantes'], 'calificable' => $data['calificable'],
            'llevar_planilla' => $data['llevar_planilla'], 'peso' => $data['peso'] ?? null,
            'disponible_desde' => $from, 'disponible_hasta' => $until,
            'fecha_limite' => $utc($data['fecha_limite'] ?? null), 'zona_publicacion' => $timezone];
    }

    private function resourceData(AulaRecurso $resource, bool $student = false): array
    {
        return ['token' => Token::for('aula-recurso', $resource->id), 'tipo' => $resource->tipo,
            'titulo' => $resource->titulo, 'contenido' => $resource->contenido,
            'estado' => $resource->estado, 'visible_estudiantes' => $resource->visible_estudiantes,
            'calificable' => $resource->calificable, 'llevar_planilla' => $resource->llevar_planilla,
            'actividad_token' => $resource->actividad_id ? Token::for('actividad-evaluacion', $resource->actividad_id) : null,
            'peso' => $resource->peso, 'disponible_desde' => $resource->disponible_desde,
            'disponible_hasta' => $resource->disponible_hasta, 'fecha_limite' => $resource->fecha_limite,
            'zona_publicacion' => $resource->zona_publicacion, 'version' => $resource->version,
            'adjuntos' => AulaAdjunto::where('recurso_id', $resource->id)->get()->map(AulaArchivoController::present(...)),
            ...($student ? [] : ['configuracion' => $resource->configuracion])];
    }

    private function aula(string $token): Aula
    {
        $aula = Token::find('aula', $token, Aula::query());
        abort_unless($aula, 404);

        return $aula;
    }
}
