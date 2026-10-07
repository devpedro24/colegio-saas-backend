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
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\Academico\Periodo;
use App\Models\Academico\Preinforme;
use App\Models\StoredFile;
use App\Services\AcademicPlanAccess;
use App\Services\AulaAccess;
use App\Services\AulaContentPolicy;
use App\Services\AulaGradebookService;
use App\Services\AulaProgressService;
use App\Services\EscalaVisualService;
use App\Services\SieeConfiguration;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken as Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
                ->join('aulas as a', fn ($join) => $join->on('a.grupo_id', '=', 'g.id')->on('a.materia_id', '=', 'm.id'))
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
                'aula_token' => Token::for('aula', $row->aula_id),
                'grupo_token' => Token::for('grupo', $row->grupo_id), 'grupo' => $groupLabel ?: $row->grupo,
                'materia_token' => Token::for('materia', $row->materia_id), 'materia' => $row->materia,
                'docente' => $row->docente ?: 'Sin asignar', 'portada_token' => $row->portada_token,
            ]);
            if ($own && ! $all) {
                $enrollmentId = DB::table('matriculas')->where('estudiante_id', $user->id)
                    ->where('ano_lectivo_id', $year->id)->where('grupo_id', $selectedGroup)
                    ->where('estado', 'activa')->value('id');
                if ($enrollmentId) {
                    $aulas = Aula::whereIn('id', $rows->pluck('aula_id')->filter()->all())->get();
                    $progress = app(AulaProgressService::class)->forAulas($aulas, (int) $enrollmentId, $this->access);
                    $cards = $cards->map(fn ($card) => [...$card,
                        'progreso' => $progress[$card['aula_token']] ?? ['completados' => 0, 'total' => 0, 'porcentaje' => 0]]);
                }
            }
        }

        return response()->json(['data' => [
            'anos' => $years->map(fn ($item) => ['token' => Token::for('ano-lectivo', $item->id), 'nombre' => $item->nombre, 'estado' => $item->estado]),
            'ano_token' => $year ? Token::for('ano-lectivo', $year->id) : null,
            'grupos' => $groups, 'aulas' => $cards, 'requiere_grupo' => $requiresGroup,
            'grupo_seleccionado' => $selectedGroup ? Token::for('grupo', $selectedGroup) : null,
            'puede_gestionar' => $user->can('aula.recursos.gestionar'),
            'puede_configurar' => $user->hasRole('rector') && $user->can('aula.configurar'),
        ]]);
    }

    public function configuracion(Request $request): JsonResponse
    {
        $this->plan->requireAula();
        abort_unless($request->user()->hasRole('rector'), 403);
        $year = AnoLectivo::where('estado', 'en_curso')->orderByDesc('fecha_inicio')->first()
            ?? AnoLectivo::orderByDesc('fecha_inicio')->first();
        $periods = $year ? Periodo::where('ano_lectivo_id', $year->id)->orderBy('orden')
            ->get(['orden', 'nombre'])->map(fn ($period) => [
                'orden' => $period->orden, 'nombre' => $period->nombre,
            ])->all() : [];

        return response()->json(['data' => [
            ...app(AulaContentPolicy::class)->configuration(),
            'puede_configurar_colores' => $this->plan->aulaColors()
                && $request->user()->can('aula.apariencia.configurar'),
            'periodos_configurables' => $periods ?: [
                ['orden' => 1, 'nombre' => 'Período 1'], ['orden' => 2, 'nombre' => 'Período 2'],
                ['orden' => 3, 'nombre' => 'Período 3'], ['orden' => 4, 'nombre' => 'Período 4'],
            ],
        ]]);
    }

    public function guardarConfiguracion(Request $request): JsonResponse
    {
        $this->plan->requireAula();
        abort_unless($request->user()->hasRole('rector'), 403);
        $data = $request->validate([
            'permitir_edicion_periodos_cerrados' => ['required', 'boolean'],
            'colores_periodos' => ['sometimes', 'array'],
            'colores_periodos.*' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_preinforme' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        if (isset($data['colores_periodos'])) {
            foreach (array_keys($data['colores_periodos']) as $order) {
                abort_unless(ctype_digit((string) $order) && (int) $order >= 1 && (int) $order <= 12, 422,
                    'El orden del período debe estar entre 1 y 12.');
            }
        }
        if (isset($data['colores_periodos']) || isset($data['color_preinforme'])) {
            abort_unless($this->plan->aulaColors() && $request->user()->can('aula.apariencia.configurar'), 403);
        }
        $policy = app(AulaContentPolicy::class);
        $before = $policy->configuration();
        $new = array_replace($before, $data);
        if (isset($data['colores_periodos'])) {
            $new['colores_periodos'] = array_replace($before['colores_periodos'], $data['colores_periodos']);
        }
        DB::table('aula_politicas')->upsert([[
            'id' => 1,
            'permitir_edicion_periodos_cerrados' => $new['permitir_edicion_periodos_cerrados'],
            'colores_periodos' => json_encode($new['colores_periodos']),
            'color_preinforme' => $new['color_preinforme'],
            'created_at' => now(), 'updated_at' => now(),
        ]], ['id'], ['permitir_edicion_periodos_cerrados', 'colores_periodos', 'color_preinforme', 'updated_at']);
        AuditLogger::tenant($request->user(), 'UPDATE', 'aula_politica', '1', $before, $data);

        return response()->json(['data' => $policy->configuration()]);
    }

    public function ver(Request $request, string $aulaToken): JsonResponse
    {
        $aula = $this->aula($aulaToken);
        $this->access->view($request->user(), $aula);
        $student = $this->access->isStudent($request->user(), $aula);
        $sectionRows = AulaSeccion::with(['periodo', 'preinforme', 'recursos' => fn ($query) => $student ? $query : $query->withTrashed()])
            ->when(! $student, fn ($query) => $query->withTrashed())
            ->where('aula_id', $aula->id)->get()->sort(fn ($a, $b) =>
                [$a->periodo->orden, $a->preinforme?->orden ?? -1, $a->orden, $a->id]
                <=> [$b->periodo->orden, $b->preinforme?->orden ?? -1, $b->orden, $b->id])->values();
        $resourceIds = $sectionRows->flatMap(fn ($section) => $section->recursos->pluck('id'))->all();
        $attachments = AulaAdjunto::whereIn('recurso_id', $resourceIds)->get()->groupBy('recurso_id');
        $storedFiles = StoredFile::where('tenant_id', (string) tenant()->id)
            ->whereIn('public_token', $attachments->flatten()->pluck('archivo_token')->unique()->all())
            ->get()->keyBy('public_token');
        $states = $student ? app(AulaProgressService::class)->forResources(
            $sectionRows->flatMap(fn ($section) => $section->visible_estudiantes
                ? $section->recursos->filter(fn ($resource) => $this->access->listed($resource)) : collect())->values(),
            $this->access->enrollment($request->user(), $aula)->id) : [];
        $sections = $sectionRows
            ->filter(fn ($section) => ! $student || $section->visible_estudiantes)
            ->map(fn ($section) => [
                'token' => Token::for('aula-seccion', $section->id), 'titulo' => $section->titulo,
                'periodo' => $section->periodo->nombre, 'periodo_token' => Token::for('periodo', $section->periodo_id),
                'preinforme_token' => $section->preinforme_id ? Token::for('preinforme', $section->preinforme_id) : null,
                'visible_estudiantes' => $section->visible_estudiantes, 'orden' => $section->orden,
                'eliminado' => $section->trashed(),
                'recursos' => $section->recursos->filter(fn ($resource) => ! $student || $this->access->listed($resource))
                    ->map(fn ($resource) => [...($student && ! $this->access->visible($resource)
                        ? $this->scheduledResourceData($resource)
                        : $this->resourceData($resource, $student, $attachments->get($resource->id, collect()), $storedFiles)),
                        'eliminado' => $resource->trashed(),
                        ...($student ? ['progreso' => $states[$resource->id] ?? 'sin_iniciar'] : [])])->values(),
            ])->values();
        $periods = Periodo::where('ano_lectivo_id', $aula->ano_lectivo_id)->orderBy('orden')->get();
        $preinformes = Preinforme::whereIn('periodo_id', $periods->pluck('id'))->orderBy('orden')->get()->groupBy('periodo_id');

        $contentConfiguration = app(AulaContentPolicy::class)->configuration();
        if (! $this->plan->aulaColors()) {
            $contentConfiguration['colores_periodos'] = AulaContentPolicy::DEFAULT_PERIOD_COLORS;
            $contentConfiguration['color_preinforme'] = AulaContentPolicy::DEFAULT_PREINFORME_COLOR;
        }

        return response()->json(['data' => [
            'token' => Token::for('aula', $aula->id), 'grado' => $aula->grupo->grado->nombre,
            'grupo' => $aula->grupo->nombre, 'materia' => $aula->materia->nombre,
            'portada_token' => $aula->portada_token, 'estudiante' => $student,
            'limite_archivo_bytes' => (int) config('storage.max_file_bytes'),
            'puede_gestionar' => ! $student && $this->access->canManage($request->user(), $aula),
            'puede_restaurar' => ! $student && $this->access->canManage($request->user(), $aula, 'aula.contenido.restaurar'),
            'permitir_edicion_periodos_cerrados' => $contentConfiguration['permitir_edicion_periodos_cerrados'],
            'colores_periodos' => $contentConfiguration['colores_periodos'],
            'color_preinforme' => $contentConfiguration['color_preinforme'],
            'permisos' => [
                'crear' => ! $student && $this->access->canContent($request->user(), $aula, 'crear'),
                'editar' => ! $student && $this->access->canContent($request->user(), $aula, 'editar'),
                'publicar' => ! $student && $this->access->canContent($request->user(), $aula, 'publicar'),
                'archivar' => ! $student && $this->access->canContent($request->user(), $aula, 'archivar'),
                'eliminar' => ! $student && $this->access->canContent($request->user(), $aula, 'eliminar'),
                'archivos' => ! $student && $this->access->canManage($request->user(), $aula, 'aula.archivos.gestionar'),
                'planilla' => ! $student && $this->access->canManage($request->user(), $aula, 'aula.planilla.vincular'),
                'evaluaciones' => ! $student && $this->access->canManage($request->user(), $aula, 'aula.evaluaciones.gestionar'),
            ],
            'periodos' => $periods->map(fn ($p) => ['token' => Token::for('periodo', $p->id), 'nombre' => $p->nombre,
                'orden' => $p->orden,
                'estado' => $p->estado, 'preinformes' => $preinformes->get($p->id, collect())
                    ->map(fn ($pre) => ['token' => Token::for('preinforme', $pre->id), 'nombre' => $pre->nombre])]),
            'secciones' => $sections,
        ]]);
    }

    public function crearSeccion(Request $request, string $aulaToken): JsonResponse
    {
        $aula = $this->aula($aulaToken);
        $this->access->content($request->user(), $aula, 'crear');
        $data = $request->validate(['periodo_token' => ['required', 'string'], 'preinforme_token' => ['nullable', 'string'],
            'titulo' => ['required', 'string', 'max:160'], 'visible_estudiantes' => ['sometimes', 'boolean']]);
        if ($data['visible_estudiantes'] ?? false) $this->access->content($request->user(), $aula, 'publicar');
        abort_if(trim($data['titulo']) === '', 422, 'El nombre de la sección es obligatorio.');
        $period = Token::find('periodo', $data['periodo_token'], Periodo::where('ano_lectivo_id', $aula->ano_lectivo_id));
        abort_unless($period, 404);
        app(AulaContentPolicy::class)->assertEditable($period);
        $pre = ! empty($data['preinforme_token']) ? Token::find('preinforme', $data['preinforme_token'], Preinforme::where('periodo_id', $period->id)) : null;
        abort_if(! empty($data['preinforme_token']) && ! $pre, 404);
        $section = AulaSeccion::create(['aula_id' => $aula->id, 'periodo_id' => $period->id, 'preinforme_id' => $pre?->id,
            'titulo' => trim($data['titulo']), 'visible_estudiantes' => $data['visible_estudiantes'] ?? false,
            'orden' => (int) AulaSeccion::where('aula_id', $aula->id)->max('orden') + 1,
            'autor_id' => $request->user()->id]);
        AuditLogger::tenant($request->user(), 'CREATE', 'aula_seccion', (string) $section->id, null, $section->toArray());

        return response()->json(['data' => ['token' => Token::for('aula-seccion', $section->id)]], 201);
    }

    /** Read-only destination preview; it never creates a gradebook component. */
    public function planillaDestino(Request $request, string $sectionToken): JsonResponse
    {
        $section = Token::find('aula-seccion', $sectionToken, AulaSeccion::query());
        abort_unless($section, 404);
        $aula = $section->aula;
        $this->access->manages($request->user(), $aula, 'aula.planilla.vincular');
        $assignment = $this->access->assignment($aula);
        abort_unless($assignment, 422, 'Asigna un docente antes de vincular la planilla.');
        app(\App\Services\GradebookService::class)->authorizeAssignment($request->user(), $assignment, write: true);
        $period = $section->periodo;
        $usesReports = (bool) ($period->configuracion_notas['usar_preinformes'] ?? false);
        $query = ComponenteEvaluacion::where('asignacion_id', $assignment->id)->where('periodo_id', $period->id);
        if ($usesReports) {
            $query->where('preinforme_id', $section->preinforme_id ?? -1);
        } else {
            $query->whereNull('preinforme_id');
        }
        $components = $query->with('actividades')->get();

        return response()->json(['data' => [
            'grupo' => $aula->grupo->nombre, 'asignatura' => $aula->materia->nombre,
            'periodo' => $period->nombre, 'estado_periodo' => $period->estado,
            'preinforme' => $section->preinforme?->nombre,
            'requiere_preinforme' => $usesReports && ! $section->preinforme_id,
            'componentes' => $components->map(fn ($component) => [
                'token' => Token::for('componente-evaluacion', $component->id),
                'nombre' => $component->nombre, 'modo' => $component->modo,
                'peso_utilizado' => (string) $component->actividades->sum(fn ($activity) => (float) ($activity->peso ?? 0)),
            ])->values(),
        ]]);
    }

    public function editarSeccion(Request $request, string $token): JsonResponse
    {
        $section = Token::find('aula-seccion', $token, AulaSeccion::query());
        abort_unless($section, 404);
        $this->access->content($request->user(), $section->aula, 'editar');
        app(AulaContentPolicy::class)->assertEditable($section->periodo);
        $data = $request->validate(['titulo' => ['sometimes', 'string', 'max:160'], 'orden' => ['sometimes', 'integer', 'min:0'],
            'visible_estudiantes' => ['sometimes', 'boolean'], 'periodo_token' => ['sometimes', 'required', 'string'],
            'preinforme_token' => ['nullable', 'string']]);
        if (array_key_exists('visible_estudiantes', $data) && $data['visible_estudiantes'] !== $section->visible_estudiantes) {
            $this->access->content($request->user(), $section->aula, 'publicar');
        }
        if (isset($data['titulo'])) {
            $data['titulo'] = trim($data['titulo']);
            abort_if($data['titulo'] === '', 422, 'El nombre de la sección es obligatorio.');
        }
        $period = isset($data['periodo_token'])
            ? Token::find('periodo', $data['periodo_token'], Periodo::where('ano_lectivo_id', $section->aula->ano_lectivo_id))
            : $section->periodo;
        abort_unless($period, 404);
        app(AulaContentPolicy::class)->assertEditable($period);
        $preToken = array_key_exists('preinforme_token', $data) ? $data['preinforme_token'] : null;
        $pre = $preToken ? Token::find('preinforme', $preToken, Preinforme::where('periodo_id', $period->id)) : null;
        abort_if($preToken && ! $pre, 404);
        $newPreId = array_key_exists('preinforme_token', $data) ? $pre?->id : $section->preinforme_id;
        abort_if($newPreId && ! Preinforme::where('id', $newPreId)->where('periodo_id', $period->id)->exists(), 422,
            'El preinforme debe pertenecer al período seleccionado.');
        abort_if($period->estaCerrado() && $period->id !== $section->periodo_id
            && $section->recursos()->where('llevar_planilla', true)->exists(), 422,
            'No se puede mover a un período cerrado una sección con recursos vinculados o pendientes de planilla.');
        abort_if(($period->id !== $section->periodo_id || $newPreId !== $section->preinforme_id)
            && $section->recursos()->where(fn ($query) => $query->whereNotNull('actividad_id')
                ->orWhereHas('entregas'))->exists(), 422,
            'La sección tiene recursos con notas o entregas; no puede cambiar de período o preinforme.');
        unset($data['periodo_token'], $data['preinforme_token']);
        $data['periodo_id'] = $period->id;
        $data['preinforme_id'] = $newPreId;
        $before = $section->toArray();
        DB::transaction(function () use ($section, $data, $request): void {
            $section->update($data);
            // Un recurso previamente marcado como visible no debe quedar invisible
            // solo porque su antiguo estado seguía en borrador.
            if (($data['visible_estudiantes'] ?? false) === true) {
                foreach (AulaRecurso::where('seccion_id', $section->id)->where('visible_estudiantes', true)
                    ->where('estado', 'borrador')->get() as $resource) {
                    $old = $resource->toArray();
                    $resource->update(['estado' => 'publicado', 'version' => $resource->version + 1]);
                    AuditLogger::tenant($request->user(), 'UPDATE', 'aula_recurso', (string) $resource->id,
                        $old, $resource->toArray(), 'Publicación de sección');
                }
            }
        });
        AuditLogger::tenant($request->user(), 'UPDATE', 'aula_seccion', (string) $section->id, $before, $section->toArray());

        return response()->json(['data' => ['token' => $token]]);
    }

    public function eliminarSeccion(Request $request, string $token): JsonResponse
    {
        $section = Token::find('aula-seccion', $token, AulaSeccion::query());
        abort_unless($section, 404);
        $this->access->content($request->user(), $section->aula, 'eliminar');
        app(AulaContentPolicy::class)->assertEditable($section->periodo);
        DB::transaction(function () use ($request, $section): void {
            foreach ($section->recursos as $resource) {
                $before = $resource->toArray();
                $resource->update(['deleted_with_section' => true]);
                $resource->delete();
                AuditLogger::tenant($request->user(), 'DELETE', 'aula_recurso', (string) $resource->id,
                    $before, $resource->toArray(), 'Eliminado con sección');
            }
            $before = $section->toArray();
            $section->delete();
            AuditLogger::tenant($request->user(), 'DELETE', 'aula_seccion', (string) $section->id,
                $before, $section->toArray());
        });
        return response()->json(['data' => ['token' => $token]]);
    }

    public function restaurarSeccion(Request $request, string $token): JsonResponse
    {
        $section = Token::find('aula-seccion', $token, AulaSeccion::withTrashed());
        abort_unless($section && $section->trashed(), 404);
        $this->access->manages($request->user(), $section->aula, 'aula.contenido.restaurar');
        app(AulaContentPolicy::class)->assertEditable($section->periodo);
        DB::transaction(function () use ($request, $section): void {
            $before = $section->toArray();
            $section->restore();
            AuditLogger::tenant($request->user(), 'RESTORE', 'aula_seccion', (string) $section->id,
                $before, $section->toArray());
            foreach ($section->recursos()->withTrashed()->where('deleted_with_section', true)->get() as $resource) {
                $old = $resource->toArray();
                $resource->restore();
                $resource->update(['deleted_with_section' => false]);
                AuditLogger::tenant($request->user(), 'RESTORE', 'aula_recurso', (string) $resource->id,
                    $old, $resource->toArray(), 'Restaurado con sección');
            }
        });
        return response()->json(['data' => ['token' => $token]]);
    }

    public function eliminarRecurso(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource, 404);
        $this->access->content($request->user(), $resource->seccion->aula, 'eliminar', $resource->tipo === 'cuestionario');
        app(AulaContentPolicy::class)->assertEditable($resource->seccion->periodo);
        $before = $resource->toArray();
        $resource->delete();
        AuditLogger::tenant($request->user(), 'DELETE', 'aula_recurso', (string) $resource->id,
            $before, $resource->toArray());
        return response()->json(['data' => ['token' => $token]]);
    }

    public function restaurarRecurso(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::withTrashed());
        abort_unless($resource && $resource->trashed(), 404);
        $section = AulaSeccion::withTrashed()->findOrFail($resource->seccion_id);
        $this->access->manages($request->user(), $section->aula, 'aula.contenido.restaurar');
        abort_if($section->trashed(), 422, 'Restaura primero la sección.');
        app(AulaContentPolicy::class)->assertEditable($section->periodo);
        $before = $resource->toArray();
        $resource->restore();
        AuditLogger::tenant($request->user(), 'RESTORE', 'aula_recurso', (string) $resource->id,
            $before, $resource->toArray());
        return response()->json(['data' => ['token' => $token]]);
    }

    public function crearRecurso(Request $request, string $sectionToken): JsonResponse
    {
        $section = Token::find('aula-seccion', $sectionToken, AulaSeccion::query());
        abort_unless($section, 404);
        $this->access->content($request->user(), $section->aula, 'crear', $request->input('tipo') === 'cuestionario');
        app(AulaContentPolicy::class)->assertEditable($section->periodo);
        $data = $this->resourceInput($request);
        if ($data['visible_estudiantes']) $this->access->content($request->user(), $section->aula, 'publicar');
        if ($data['llevar_planilla']) $this->access->manages($request->user(), $section->aula, 'aula.planilla.vincular');
        abort_if($data['llevar_planilla'] && $section->periodo->estaCerrado(), 422,
            'No se puede vincular una tarea o evaluación a la planilla de un período cerrado.');
        abort_if($data['tipo'] === 'cuestionario' && $data['visible_estudiantes'], 422,
            'Agrega y guarda las preguntas antes de publicar el cuestionario.');
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
        $this->access->content($request->user(), $resource->seccion->aula, 'editar',
            $resource->tipo === 'cuestionario' || $request->input('tipo') === 'cuestionario');
        app(AulaContentPolicy::class)->assertEditable($resource->seccion->periodo);
        $data = $this->resourceInput($request);
        if ($data['visible_estudiantes'] !== $resource->visible_estudiantes || $data['visible_estudiantes']) {
            $this->access->content($request->user(), $resource->seccion->aula, 'publicar');
        }
        if ($data['llevar_planilla'] && ! $resource->llevar_planilla) {
            $this->access->manages($request->user(), $resource->seccion->aula, 'aula.planilla.vincular');
        }
        abort_if($data['tipo'] === 'cuestionario' && $data['visible_estudiantes']
            && ! $resource->preguntas()->exists(), 422,
            'Agrega y guarda las preguntas antes de publicar el cuestionario.');
        abort_unless((int) $request->input('version') === $resource->version, 409, 'El recurso cambió. Recarga antes de guardar.');
        $targetSection = ! empty($data['seccion_token'])
            ? Token::find('aula-seccion', $data['seccion_token'], AulaSeccion::where('aula_id', $resource->seccion->aula_id))
            : $resource->seccion;
        abort_unless($targetSection, 404);
        app(AulaContentPolicy::class)->assertEditable($targetSection->periodo);
        abort_if($data['llevar_planilla'] && ! $resource->actividad_id && $targetSection->periodo->estaCerrado(), 422,
            'No se puede vincular una tarea o evaluación a la planilla de un período cerrado.');
        abort_if($targetSection->id !== $resource->seccion_id
            && ($resource->actividad_id || $resource->entregas()->exists())
            && ($targetSection->periodo_id !== $resource->seccion->periodo_id
                || $targetSection->preinforme_id !== $resource->seccion->preinforme_id), 422,
            'Un recurso con notas o entregas solo puede moverse a otra sección del mismo período y preinforme.');
        abort_if($resource->actividad_id && ! ($data['llevar_planilla'] ?? $resource->llevar_planilla), 422,
            'La actividad ya está vinculada a la planilla. Conserva el vínculo y las notas históricas.');
        abort_if($resource->actividad_id && ! $data['calificable'], 422,
            'La actividad ya está vinculada a la planilla y debe conservar su calificación.');
        abort_if($resource->actividad_id && ($data['titulo'] !== $resource->titulo
            || (($data['peso'] ?? null) === null) !== ($resource->peso === null)
            || (($data['peso'] ?? null) !== null && $resource->peso !== null
                && ! BigDecimal::of((string) $data['peso'])->isEqualTo((string) $resource->peso))), 422,
            'La actividad vinculada conserva su nombre y porcentaje para proteger las notas históricas.');
        abort_if($resource->tipo !== $data['tipo'] && ($resource->preguntas()->exists() || $resource->entregas()->exists()), 422,
            'El recurso ya tiene preguntas o entregas y no puede cambiar de tipo.');
        DB::transaction(function () use ($resource, $targetSection, $data, $request): void {
            $before = $resource->toArray();
            $resource->update([...$this->resourceFields($data, $resource),
                'seccion_id' => $targetSection->id,
                'orden' => $targetSection->id === $resource->seccion_id ? $resource->orden
                    : (int) AulaRecurso::where('seccion_id', $targetSection->id)->max('orden') + 1,
                'version' => $resource->version + 1]);
            if ($resource->llevar_planilla && ! $resource->actividad_id) app(AulaGradebookService::class)->link($resource, $request->user());
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_recurso', (string) $resource->id, $before, $resource->toArray());
        });

        return response()->json(['data' => $this->resourceData($resource)]);
    }

    public function vincularPlanilla(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource, 404);
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.planilla.vincular');
        abort_unless($resource->calificable && $resource->llevar_planilla, 422,
            'Este recurso no tiene solicitado el vínculo con planilla.');
        abort_if($resource->seccion->periodo->estaCerrado(), 422, 'El período está cerrado.');
        $activity = app(AulaGradebookService::class)->link($resource, $request->user());
        abort_unless($activity, 422, 'El período aún no está abierto; el vínculo sigue pendiente.');

        return response()->json(['data' => ['estado_vinculo_planilla' => 'vinculado',
            'actividad_token' => Token::for('actividad-evaluacion', $activity->id)]]);
    }

    public function archivarRecurso(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource, 404);
        $this->access->content($request->user(), $resource->seccion->aula, 'archivar', $resource->tipo === 'cuestionario');
        app(AulaContentPolicy::class)->assertEditable($resource->seccion->periodo);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        abort_unless($data['version'] === $resource->version, 409, 'El recurso cambió. Recarga antes de archivar.');
        DB::transaction(function () use ($resource, $request): void {
            $before = $resource->toArray();
            $resource->update(['estado' => 'archivado', 'visible_estudiantes' => false,
                'version' => $resource->version + 1]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_recurso', (string) $resource->id,
                $before, $resource->toArray(), 'Archivado desde Aula');
        });

        return response()->json(['data' => $this->resourceData($resource)]);
    }

    public function verRecurso(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource, 404);
        abort_if($resource->trashed() || ! $resource->seccion || $resource->seccion->trashed(), 404);
        $this->access->view($request->user(), $resource->seccion->aula);
        $student = $this->access->isStudent($request->user(), $resource->seccion->aula);
        if ($student && ! $this->access->visible($resource)) {
            abort_unless($this->access->listed($resource), 404);

            return response()->json(['data' => [
                ...$this->scheduledResourceData($resource),
                'estudiante' => true,
                'aula_token' => Token::for('aula', $resource->seccion->aula_id),
                'seccion_token' => Token::for('aula-seccion', $resource->seccion_id),
                'puede_interactuar' => false,
            ]]);
        }
        $this->access->readResource($request->user(), $resource);
        $data = $this->resourceData($resource, $student);
        if ($student) $data['progreso'] = app(AulaProgressService::class)->forResources(collect([$resource]),
            $this->access->enrollment($request->user(), $resource->seccion->aula)->id)[$resource->id] ?? 'sin_iniciar';
        $data['estudiante'] = $student;
        $data['aula_token'] = Token::for('aula', $resource->seccion->aula_id);
        $data['seccion_token'] = Token::for('aula-seccion', $resource->seccion_id);
        $contentEditable = ! $resource->seccion->periodo->estaCerrado()
            || app(AulaContentPolicy::class)->configuration()['permitir_edicion_periodos_cerrados'];
        $data['puede_gestionar'] = ! $student && $this->access->canContent($request->user(),
            $resource->seccion->aula, 'editar', $resource->tipo === 'cuestionario')
            && $contentEditable
            && (! $resource->visible_estudiantes || $this->access->canContent($request->user(),
                $resource->seccion->aula, 'publicar'));
        $data['puede_adjuntar'] = $data['puede_gestionar'] && $this->access->canManage($request->user(),
            $resource->seccion->aula, 'aula.archivos.gestionar');
        $data['puede_planilla'] = ! $student && $this->access->canManage($request->user(),
            $resource->seccion->aula, 'aula.planilla.vincular') && ! $resource->seccion->periodo->estaCerrado();
        $data['puede_calificar'] = ! $student && $this->access->canManage($request->user(),
            $resource->seccion->aula, 'aula.entregas.calificar');
        $data['puede_evaluar'] = ! $student && $this->access->canContent($request->user(),
            $resource->seccion->aula, 'editar', true);
        $data['puede_calificar_cuestionario'] = ! $student && $this->access->canManage($request->user(),
            $resource->seccion->aula, 'aula.evaluaciones.calificar');
        $data['puede_reactivar_intento'] = ! $student && $this->access->canManage($request->user(),
            $resource->seccion->aula, 'aula.intentos.reactivar');
        if (! $student) {
            $data['estado_vinculo_planilla'] = ! $resource->llevar_planilla ? 'no_solicitado'
                : ($resource->actividad_id ? 'vinculado'
                    : ($resource->seccion->periodo->estado === Periodo::ESTADO_PLANIFICADO
                        ? 'pendiente_apertura' : 'pendiente_configuracion'));
        }
        if ($student) {
            $until = $resource->disponible_hasta ?? ($resource->tipo === 'tarea' ? $resource->fecha_limite : null);
            $data['puede_interactuar'] = $resource->estado !== 'cerrado'
                && ! $resource->seccion->periodo->estaCerrado()
                && (! $until || now('UTC')->lte($until));
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
            $data['tiene_intentos'] = $resource->intentos()->exists();
            $data['preguntas'] = $resource->preguntas()->with('medios')->get()->map(fn ($q) => [
                'token' => Token::for('aula-pregunta', $q->id), 'tipo' => $q->tipo,
                'enunciado' => $q->enunciado, 'opciones' => $q->opciones, 'puntos' => $q->puntos,
                'medios' => $q->medios->map(AulaMedioController::presentQuestion(...))->values(),
                ...($data['puede_evaluar'] ? ['respuesta_correcta' => $q->respuesta_correcta,
                    'puntajes_opciones' => $q->puntajes_opciones, 'rubrica' => $q->rubrica] : []),
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

    public function abrirRecurso(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource, 404);
        $this->access->readResource($request->user(), $resource);
        abort_unless($this->access->isStudent($request->user(), $resource->seccion->aula)
            && $request->user()->can('aula.ver_propias'), 403);
        $enrollment = $this->access->enrollment($request->user(), $resource->seccion->aula);
        abort_unless($enrollment, 403);
        $created = DB::table('aula_vistas_recursos')->insertOrIgnore([
            'recurso_id' => $resource->id, 'matricula_id' => $enrollment->id, 'abierto_at' => now('UTC'),
        ]);
        if ($created) AuditLogger::tenant($request->user(), 'CREATE', 'aula_vista_recurso',
            $resource->id.':'.$enrollment->id, null, ['recurso_id' => $resource->id, 'matricula_id' => $enrollment->id]);

        $progress = app(AulaProgressService::class);
        $aula = $resource->seccion->aula;

        return response()->json(['data' => [
            'progreso' => $progress->forResources(collect([$resource]), $enrollment->id)[$resource->id] ?? 'sin_iniciar',
            'aula_token' => Token::for('aula', $aula->id),
            'resumen' => $progress->forAulas(collect([$aula]), $enrollment->id, $this->access)[Token::for('aula', $aula->id)],
        ]]);
    }

    public function entregar(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource && $resource->tipo === 'tarea', 404);
        $this->access->readResource($request->user(), $resource);
        abort_unless($request->user()->can('aula.entregas.enviar'), 403);
        $until = $resource->disponible_hasta ?? $resource->fecha_limite;
        abort_if($resource->estado === 'cerrado' || $resource->seccion->periodo->estaCerrado()
            || ($until && now('UTC')->gt($until)), 422,
            'El plazo de interacción de este recurso terminó.');
        $enrollment = $this->access->enrollment($request->user(), $resource->seccion->aula);
        abort_unless($enrollment, 403);
        $data = $request->validate(['texto' => ['nullable', 'string', 'max:20000']]);
        $submission = AulaEntrega::firstOrNew(['recurso_id' => $resource->id, 'matricula_id' => $enrollment->id]);
        abort_if($submission->exists && $submission->nota !== null, 422, 'La entrega ya fue calificada.');
        abort_if($submission->exists && $submission->estado !== 'borrador'
            && ! ($resource->configuracion['reenvios'] ?? false), 422, 'Ya entregaste esta tarea.');
        abort_if($submission->estado === 'revisada', 422, 'El docente ya revisó esta entrega.');
        abort_if(trim((string) ($data['texto'] ?? '')) === ''
            && (! $submission->exists || ! AulaAdjunto::where('entrega_id', $submission->id)->exists()), 422,
            'Escribe una respuesta o adjunta al menos un archivo antes de entregar.');
        $before = $submission->exists ? $submission->toArray() : null;
        $submission->fill(['texto' => $data['texto'] ?? null, 'estado' => 'entregada', 'entregada_at' => now('UTC'),
            'version' => ($submission->version ?? 0) + 1])->save();
        AuditLogger::tenant($request->user(), $before ? 'UPDATE' : 'CREATE', 'aula_entrega', (string) $submission->id, $before, $submission->toArray());

        return response()->json(['data' => ['token' => Token::for('aula-entrega', $submission->id)]], 201);
    }

    /** Reserve a private draft so files can be uploaded before the final submission. */
    public function borradorEntrega(Request $request, string $token): JsonResponse
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
        $submission = AulaEntrega::firstOrCreate(['recurso_id' => $resource->id, 'matricula_id' => $enrollment->id],
            ['estado' => 'borrador', 'version' => 1]);
        abort_unless($submission->estado === 'borrador', 422, 'Esta tarea ya fue entregada.');
        if ($submission->wasRecentlyCreated) {
            AuditLogger::tenant($request->user(), 'CREATE', 'aula_entrega', (string) $submission->id, null,
                $submission->toArray(), 'Borrador para adjuntar evidencia antes de entregar.');
        }

        return response()->json(['data' => ['token' => Token::for('aula-entrega', $submission->id)]], 201);
    }

    public function entregas(Request $request, string $resourceToken): JsonResponse
    {
        $resource = Token::find('aula-recurso', $resourceToken, AulaRecurso::query());
        abort_unless($resource && $resource->tipo === 'tarea', 404);
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.entregas.calificar');
        $official = $resource->actividad_id ? Calificacion::where('actividad_id', $resource->actividad_id)
            ->get()->keyBy('matricula_id') : collect();
        return response()->json(['data' => AulaEntrega::with('matricula.estudiante', 'escalaOpcion')->where('recurso_id', $resource->id)
            ->where('estado', '!=', 'borrador')->get()->map(fn ($item) => [
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
        abort_unless(in_array($submission->estado, ['entregada', 'calificada'], true), 422,
            'Solo se puede calificar una entrega enviada.');
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

    public function revisarEntrega(Request $request, string $token): JsonResponse
    {
        $submission = Token::find('aula-entrega', $token, AulaEntrega::query());
        abort_unless($submission, 404);
        $resource = $submission->recurso;
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.entregas.calificar');
        abort_if($resource->calificable, 422, 'Esta tarea requiere una calificación.');
        abort_if($resource->seccion->periodo->estaCerrado(), 422, 'El período está cerrado.');
        abort_unless($submission->estado === 'entregada', 422, 'La entrega ya fue revisada.');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'retroalimentacion' => ['nullable', 'string', 'max:10000']]);
        abort_unless($submission->version === $data['version'], 409, 'La entrega cambió. Recarga antes de revisarla.');
        DB::transaction(function () use ($request, $submission, $data): void {
            $before = $submission->toArray();
            $submission->update(['estado' => 'revisada', 'retroalimentacion' => $data['retroalimentacion'] ?? null,
                'calificada_por' => $request->user()->id, 'version' => $submission->version + 1]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_entrega', (string) $submission->id,
                $before, $submission->toArray(), 'Revisión sin nota');
        });

        return response()->json(['data' => ['revisada' => true]]);
    }

    private function resourceInput(Request $request): array
    {
        $data = $request->validate([
            'version' => ['sometimes', 'integer', 'min:1'],
            'tipo' => ['required', Rule::in(['texto', 'archivo', 'tarea', 'cuestionario'])],
            'seccion_token' => ['sometimes', 'string'],
            'titulo' => ['required', 'string', 'max:160'],
            'contenido' => ['nullable', 'array'], 'contenido.html' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'contenido.bloques' => ['sometimes', 'array', 'max:100'],
            'contenido.bloques.*.tipo' => ['required_with:contenido.bloques', Rule::in(['titulo', 'parrafo', 'aviso', 'lista', 'tarjeta', 'enlace'])],
            'contenido.bloques.*.texto' => ['nullable', 'string', 'max:10000'],
            'contenido.bloques.*.url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'estado' => ['sometimes', Rule::in(['borrador', 'programado', 'publicado', 'cerrado', 'archivado'])],
            'visible_estudiantes' => ['required', 'boolean'],
            'calificable' => ['required', 'boolean'], 'llevar_planilla' => ['required', 'boolean'],
            'peso' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'disponible_desde' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'disponible_hasta' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'configuracion' => ['nullable', 'array'],
            'configuracion.reenvios' => ['sometimes', 'boolean'],
            'configuracion.completar_al' => ['sometimes', Rule::in(['abrir', 'entregar', 'revisar', 'finalizar'])],
            'configuracion.intentos' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'configuracion.duracion_minutos' => ['sometimes', 'integer', 'min:1', 'max:480'],
            'configuracion.duracion_minima_minutos' => ['sometimes', 'integer', 'min:0', 'max:480'],
            'configuracion.preguntas_por_pagina' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'configuracion.mezclar_preguntas' => ['sometimes', 'boolean'],
            'configuracion.mezclar_respuestas' => ['sometimes', 'boolean'],
            'configuracion.permitir_regresar' => ['sometimes', 'boolean'],
            'configuracion.permitir_editar_respuestas' => ['sometimes', 'boolean'],
            'configuracion.vigilado' => ['sometimes', 'boolean'],
            'configuracion.incidentes_permitidos' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'configuracion.transferencia' => ['sometimes', Rule::in(['confirmar', 'automatica'])],
            'configuracion.planilla_componente_token' => ['sometimes', 'nullable', 'string', 'size:24'],
        ]);
        foreach ($data['contenido']['bloques'] ?? [] as $block) {
            abort_if($block['tipo'] === 'enlace' && (empty($block['url']) || empty(trim($block['texto'] ?? ''))),
                422, 'Cada enlace necesita un nombre y una dirección HTTPS o HTTP válida.');
        }

        return $data;
    }

    private function resourceFields(array $data, ?AulaRecurso $existing = null): array
    {
        $completion = $data['configuracion']['completar_al'] ?? null;
        abort_if(($data['configuracion']['duracion_minima_minutos'] ?? 0)
            > ($data['configuracion']['duracion_minutos'] ?? 60), 422,
            'El tiempo mínimo no puede superar la duración máxima del cuestionario.');
        abort_if($completion && ! in_array($completion, match ($data['tipo']) {
            'tarea' => ['abrir', 'entregar', 'revisar'],
            'cuestionario' => ['finalizar', 'revisar'],
            default => ['abrir'],
        }, true), 422, 'El criterio de finalización no corresponde al tipo de recurso.');
        abort_if($data['llevar_planilla'] && ! $data['calificable'], 422, 'Para llevar la nota a planilla, el recurso debe ser calificable.');
        abort_if(! in_array($data['tipo'], ['tarea', 'cuestionario'], true) && $data['calificable'], 422,
            'Solo las tareas y los cuestionarios admiten calificación.');
        $timezone = $existing?->zona_publicacion ?: tenant()->zonaHorariaInstitucional();
        $utc = fn (?string $date) => $date ? Carbon::createFromFormat('Y-m-d\TH:i', $date, $timezone)->utc() : null;
        $from = $utc($data['disponible_desde'] ?? null);
        $until = $utc($data['disponible_hasta'] ?? null);
        abort_if($from && $until && $from->gt($until), 422, 'El cierre debe ser posterior a la apertura.');

        return ['tipo' => $data['tipo'], 'titulo' => trim($data['titulo']),
            'contenido' => ['bloques' => $data['contenido']['bloques'] ?? [],
                'html' => isset($data['contenido']['html'])
                    ? app(\App\Services\AulaHtmlSanitizer::class)->sanitize($data['contenido']['html']) : null],
            'configuracion' => $data['configuracion'] ?? [],
            'estado' => $data['visible_estudiantes'] ? 'publicado'
                : ($existing?->estado === 'archivado' ? 'archivado' : 'borrador'),
            'visible_estudiantes' => $data['visible_estudiantes'], 'calificable' => $data['calificable'],
            'llevar_planilla' => $data['llevar_planilla'], 'peso' => $data['peso'] ?? null,
            'disponible_desde' => $from, 'disponible_hasta' => $until,
            // Se conserva la columna histórica, pero ya no existe una segunda fecha
            // editable para tareas: su límite es exactamente "Disponible hasta".
            'fecha_limite' => $data['tipo'] === 'tarea' ? $until : null,
            'zona_publicacion' => $timezone];
    }

    private function resourceData(AulaRecurso $resource, bool $student = false,
        ?Collection $attachments = null, ?Collection $storedFiles = null): array
    {
        return ['token' => Token::for('aula-recurso', $resource->id), 'tipo' => $resource->tipo,
            'titulo' => $resource->titulo, 'contenido' => $resource->contenido,
            'estado' => $resource->estado, 'visible_estudiantes' => $resource->visible_estudiantes,
            'calificable' => $resource->calificable, 'llevar_planilla' => $resource->llevar_planilla,
            'actividad_token' => $resource->actividad_id ? Token::for('actividad-evaluacion', $resource->actividad_id) : null,
            'peso' => $resource->peso, 'disponible_desde' => $resource->disponible_desde,
            'disponible_hasta' => $resource->disponible_hasta ?? ($resource->tipo === 'tarea' ? $resource->fecha_limite : null),
            'fecha_limite' => $resource->fecha_limite,
            'zona_publicacion' => $resource->zona_publicacion, 'version' => $resource->version,
            'completar_al' => $resource->configuracion['completar_al'] ?? match ($resource->tipo) {
                'tarea' => 'entregar', 'cuestionario' => 'finalizar', default => 'abrir',
            },
            'reenvios' => $resource->tipo === 'tarea' && (bool) ($resource->configuracion['reenvios'] ?? false),
            'adjuntos' => ($attachments ?? AulaAdjunto::where('recurso_id', $resource->id)->get())
                ->map(fn ($attachment) => $storedFiles !== null
                    ? AulaArchivoController::presentWithStored($attachment, $storedFiles->get($attachment->archivo_token))
                    : AulaArchivoController::present($attachment)),
            ...($student ? [] : ['configuracion' => $resource->configuracion])];
    }

    private function scheduledResourceData(AulaRecurso $resource): array
    {
        return [
            'token' => Token::for('aula-recurso', $resource->id), 'tipo' => $resource->tipo,
            'titulo' => $resource->titulo, 'estado' => $resource->estado,
            'visible_estudiantes' => true, 'calificable' => $resource->calificable,
            'disponible_desde' => $resource->disponible_desde,
            'disponible_hasta' => $resource->disponible_hasta ?? ($resource->tipo === 'tarea' ? $resource->fecha_limite : null),
            'zona_publicacion' => $resource->zona_publicacion,
            'no_disponible' => 'programado',
            'contenido' => ['bloques' => []], 'adjuntos' => [],
        ];
    }

    private function aula(string $token): Aula
    {
        $aula = Token::find('aula', $token, Aula::query());
        abort_unless($aula, 404);

        return $aula;
    }
}
