<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\IndicadorBase;
use App\Models\Academico\IndicadorVinculo;
use App\Models\Academico\Materia;
use App\Models\Academico\Periodo;
use App\Services\GroupSubjectScope;
use App\Services\IndicatorHtml;
use App\Services\IndicatorPolicy;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Versioned institutional learning objectives, with optional group-specific wording. */
final class IndicadorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grupo_token' => ['required', 'string'], 'materia_token' => ['required', 'string'],
            'periodo_token' => ['required', 'string'],
        ]);
        [$group, $subject, $period] = $this->context($data);
        $this->mayReadGroup($request, $group, $subject);
        $scope = IndicatorPolicy::forYear($group->ano_lectivo_id)['alcance'];
        $bindings = IndicadorVinculo::query()
            ->join('indicadores_base as base', 'base.id', '=', 'indicadores_vinculos.indicador_id')
            ->select('indicadores_vinculos.*')
            ->where('ano_lectivo_id', $group->ano_lectivo_id)->where('grado_id', $group->grado_id)
            ->where('materia_id', $subject->id)->where('indicadores_vinculos.estado', 'activo')
            ->when($scope === 'grado', fn ($query) => $query->whereNull('indicadores_vinculos.grupo_id'))
            ->when($scope === 'grupo', fn ($query) => $query->where('indicadores_vinculos.grupo_id', $group->id))
            ->when($scope === 'mixto', fn ($query) => $query->where(fn ($inner) => $inner->whereNull('indicadores_vinculos.grupo_id')->orWhere('indicadores_vinculos.grupo_id', $group->id)))
            ->where(fn ($query) => $query->whereNull('periodos_orden')->orWhereJsonContains('periodos_orden', $period->orden))
            ->orderBy('base.orden')->orderBy('indicadores_vinculos.id')->paginate(100);
        $ids = $bindings->getCollection()->pluck('id')->all();
        $revisions = DB::table('indicadores_revisiones')->whereIn('id', $bindings->getCollection()->pluck('revision_id'))
            ->get()->keyBy('id');
        $variants = DB::table('indicadores_variantes')->whereIn('vinculo_id', $ids)->where('grupo_id', $group->id)
            ->whereIn('periodo_orden', [0, $period->orden])->get()->groupBy('vinculo_id');
        $items = $bindings->getCollection()->map(function (IndicadorVinculo $binding) use ($revisions, $variants, $period): array {
            $revision = $revisions->get($binding->revision_id);
            $exact = $variants->get($binding->id)?->firstWhere('periodo_orden', $period->orden);
            $override = $exact
                ?? $variants->get($binding->id)?->firstWhere('periodo_orden', 0);

            return [
                'indicador_token' => OpaqueUrlToken::for('indicador-base', $binding->indicador_id),
                'vinculo_token' => OpaqueUrlToken::for('indicador-vinculo', $binding->id),
                'texto' => $override?->texto ?? $revision->texto,
                'texto_base' => $revision->texto,
                'revision' => (int) $revision->version,
                'version_vinculo' => (int) $binding->version,
                'version_variante' => (int) ($exact?->version ?? 0),
                'periodos_orden' => $binding->periodos_orden,
            ];
        });

        return response()->json(['data' => [
            'periodo_token' => OpaqueUrlToken::for('periodo', $period->id),
            'indicadores' => $items, 'pagina' => $bindings->currentPage(),
            'ultima_pagina' => $bindings->lastPage(), 'total' => $bindings->total(),
        ]]);
    }

    public function bases(Request $request): JsonResponse
    {
        $this->mayManage($request);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim($data['search'] ?? '');
        $page = IndicadorBase::query()->where('estado', 'activo')
            ->whereNotIn('id', IndicadorVinculo::query()->whereNotNull('grupo_id')->select('indicador_id'))
            ->when($search !== '', fn ($query) => $query->where('texto', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('orden')->orderBy('id')->paginate(20);

        return response()->json(['data' => $page->getCollection()->map(fn (IndicadorBase $row) => [
            'indicador_token' => OpaqueUrlToken::for('indicador-base', $row->id),
            'texto' => $row->texto, 'orden' => (int) $row->orden, 'version' => (int) $row->version,
        ]), 'meta' => ['pagina' => $page->currentPage(), 'ultima_pagina' => $page->lastPage(), 'total' => $page->total()]]);
    }

    /** Group texts remain available as drafts when the year returns to grade scope. */
    public function borradores(Request $request): JsonResponse
    {
        $this->mayManage($request);
        $data = $request->validate([
            'ano_token' => ['required', 'string'], 'grado_token' => ['required', 'string'],
            'materia_token' => ['required', 'string'], 'periodo_token' => ['required', 'string'],
        ]);
        [$year, $grade, $subject] = $this->bindingContext([...$data, 'periodos_orden' => []], false);
        $period = OpaqueUrlToken::find('periodo', $data['periodo_token'],
            Periodo::where('ano_lectivo_id', $year->id)) ?? abort(422, 'Selecciona un período de ese año.');
        $links = IndicadorVinculo::where('ano_lectivo_id', $year->id)->where('grado_id', $grade->id)
            ->where('materia_id', $subject->id)->whereNotNull('grupo_id')
            ->where('estado', 'activo')->where(fn ($query) => $query->whereNull('periodos_orden')->orWhereJsonContains('periodos_orden', $period->orden))
            ->get();
        $revisions = DB::table('indicadores_revisiones')->whereIn('id', $links->pluck('revision_id'))
            ->get()->keyBy('id');
        $variants = DB::table('indicadores_variantes')->whereIn('vinculo_id', $links->pluck('id'))
            ->whereIn('periodo_orden', [0, $period->orden])->get()->groupBy('vinculo_id');
        $drafts = $links->map(function (IndicadorVinculo $link) use ($revisions, $variants, $period): string {
            $groupVariants = $variants->get($link->id);

            return trim($groupVariants?->firstWhere('periodo_orden', $period->orden)?->texto
                ?? $groupVariants?->firstWhere('periodo_orden', 0)?->texto
                ?? $revisions->get($link->revision_id)?->texto ?? '');
        });
        $richTexts = DB::table('indicadores_contenidos_grupo')->where('ano_lectivo_id', $year->id)
            ->where('materia_id', $subject->id)->where('periodo_id', $period->id)
            ->whereIn('grupo_id', DB::table('grupos')->where('ano_lectivo_id', $year->id)
                ->where('grado_id', $grade->id)->select('id'))
            ->pluck('texto_html')->map(fn (string $value): string => app(IndicatorHtml::class)->plain($value));
        $drafts = $drafts->concat($richTexts)->filter()->unique()->values()
            ->map(fn (string $text) => ['texto' => $text]);

        return response()->json(['data' => $drafts]);
    }

    public function opcionesAsignadas(Request $request): JsonResponse
    {
        $manager = $request->user()->can('indicadores.base.gestionar');
        abort_unless($manager || $request->user()->can('indicadores.grupo.ver'), 403);
        $userId = $request->user()->id;
        $assigned = AsignacionDocente::where('docente_id', $userId)->get(['grupo_id', 'materia_id'])
            ->groupBy('grupo_id');
        $groups = Grupo::with('grado:id,nombre')->where('estado', 'activo')
            ->when(! $manager, fn ($query) => $query->whereIn('id', $assigned->keys()->all()))
            ->orderBy('id')->get();
        $yearIds = $groups->pluck('ano_lectivo_id')->unique()->all();
        $years = AnoLectivo::whereIn('id', $yearIds)->orderByDesc('fecha_inicio')->get(['id', 'nombre', 'estado']);
        $periods = Periodo::whereIn('ano_lectivo_id', $yearIds)->orderBy('orden')->get();

        return response()->json(['data' => [
            'anos' => $years->map(fn (AnoLectivo $year) => [
                'token' => OpaqueUrlToken::for('ano-lectivo', $year->id),
                'nombre' => $year->nombre, 'estado' => $year->estado,
            ])->values(),
            'grupos' => $groups->map(function (Grupo $group) use ($assigned, $manager): array {
                $gradeName = $group->grado->nombre;
                $subjects = GroupSubjectScope::apply(Materia::query(), $group)
                    ->when(! $manager, fn ($query) => $query->whereIn('id',
                        $assigned->get($group->id)?->pluck('materia_id')->all() ?? []))
                    ->orderBy('nombre')->get(['id', 'nombre']);

                return [
                    'token' => OpaqueUrlToken::for('grupo', $group->id),
                    'ano_token' => OpaqueUrlToken::for('ano-lectivo', $group->ano_lectivo_id),
                    'grado_token' => OpaqueUrlToken::for('grado', $group->grado_id),
                    'nombre' => $group->nombre, 'grado_nombre' => $gradeName,
                    'materias' => $subjects->map(fn (Materia $subject) => [
                        'token' => OpaqueUrlToken::for('materia', $subject->id), 'nombre' => $subject->nombre,
                    ])->values(),
                ];
            })->values(),
            'periodos' => $periods->map(fn (Periodo $period) => [
                'token' => OpaqueUrlToken::for('periodo', $period->id),
                'ano_token' => OpaqueUrlToken::for('ano-lectivo', $period->ano_lectivo_id),
                'nombre' => $period->nombre, 'orden' => $period->orden, 'estado' => $period->estado,
            ])->values(),
        ]]);
    }

    public function contexto(Request $request, string $yearToken): JsonResponse
    {
        $this->mayManage($request);
        $year = $this->year($yearToken);
        $gradeToken = $request->validate(['grado_token' => ['nullable', 'string']])['grado_token'] ?? null;
        $grade = $gradeToken ? OpaqueUrlToken::find('grado', $gradeToken,
            Grado::where('ano_lectivo_id', $year->id)) : null;
        abort_if($gradeToken && ! $grade, 422, 'El grado no pertenece al año.');
        $grades = Grado::where('ano_lectivo_id', $year->id)->where('estado', 'activo')
            ->orderBy('nombre')->get(['id', 'nombre']);
        $subjects = $grade ? DB::table('materias_curriculares as curriculum')
            ->join('materias as subject', 'subject.id', '=', 'curriculum.materia_id')
            ->where('curriculum.ano_lectivo_id', $year->id)->where('curriculum.grado_id', $grade->id)
            ->where('subject.estado', 'activo')->orderBy('subject.nombre')
            ->get(['subject.id', 'subject.nombre']) : collect();
        $periods = Periodo::where('ano_lectivo_id', $year->id)->orderBy('orden')->get(['id', 'nombre', 'orden', 'estado']);

        return response()->json(['data' => [
            'grados' => $grades->map(fn ($item) => ['token' => OpaqueUrlToken::for('grado', $item->id), 'nombre' => $item->nombre]),
            'materias' => $subjects->map(fn ($item) => ['token' => OpaqueUrlToken::for('materia', $item->id), 'nombre' => $item->nombre]),
            'periodos' => $periods->map(fn ($item) => ['token' => OpaqueUrlToken::for('periodo', $item->id),
                'nombre' => $item->nombre, 'orden' => $item->orden, 'estado' => $item->estado]),
        ]]);
    }

    public function vinculos(Request $request): JsonResponse
    {
        $this->mayManage($request);
        $data = $request->validate(['ano_token' => ['required', 'string'],
            'grado_token' => ['required', 'string'], 'materia_token' => ['required', 'string'],
            'periodo_token' => ['nullable', 'string']]);
        [$year, $grade, $subject] = $this->bindingContext([...$data, 'periodos_orden' => []], false);
        $period = isset($data['periodo_token']) ? OpaqueUrlToken::find('periodo', $data['periodo_token'],
            Periodo::where('ano_lectivo_id', $year->id)) : null;
        abort_if(isset($data['periodo_token']) && ! $period, 422, 'Selecciona un período de ese año.');
        $page = IndicadorVinculo::query()->join('indicadores_base as base', 'base.id', '=', 'indicadores_vinculos.indicador_id')
            ->where('indicadores_vinculos.ano_lectivo_id', $year->id)->where('indicadores_vinculos.grado_id', $grade->id)
            ->where('indicadores_vinculos.materia_id', $subject->id)
            ->whereNull('indicadores_vinculos.grupo_id')->where('indicadores_vinculos.estado', 'activo')
            ->when($period, fn ($query) => $query->where(fn ($inner) => $inner
                ->whereNull('periodos_orden')->orWhereJsonContains('periodos_orden', $period->orden)))
            ->select('indicadores_vinculos.*')->orderBy('base.orden')->orderBy('indicadores_vinculos.id')->paginate(50);

        return response()->json(['data' => $page->getCollection()->map(fn (IndicadorVinculo $binding) => $this->presentBinding(IndicadorBase::findOrFail($binding->indicador_id), $binding)),
            'meta' => ['pagina' => $page->currentPage(), 'ultima_pagina' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function crear(Request $request): JsonResponse
    {
        $this->mayManage($request);
        $data = $this->bindingInput($request);
        [$year, $grade, $subject, $periodOrders] = $this->bindingContext($data);
        abort_if(IndicatorPolicy::forYear($year->id)['alcance'] === 'grupo', 409,
            'Este año usa indicadores por grupo.');
        $text = trim($data['texto']);
        abort_if($text === '', 422, 'Escribe el indicador.');
        $indicator = DB::transaction(function () use ($request, $year, $grade, $subject, $periodOrders, $data, $text) {
            AnoLectivo::whereKey($year->id)->lockForUpdate()->firstOrFail();
            $indicator = IndicadorBase::create([
                'texto' => $text, 'orden' => $data['orden'], 'estado' => 'activo', 'version' => 1,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $revisionId = DB::table('indicadores_revisiones')->insertGetId([
                'indicador_id' => $indicator->id, 'version' => 1, 'texto' => $text,
                'actor_id' => $request->user()->id, 'created_at' => now(),
            ]);
            $binding = IndicadorVinculo::create([
                'indicador_id' => $indicator->id, 'revision_id' => $revisionId,
                'ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'materia_id' => $subject->id,
                'periodos_orden' => $periodOrders, 'estado' => 'activo', 'version' => 1,
                'created_by' => $request->user()->id,
            ]);
            AuditLogger::tenant($request->user(), 'CREATE', 'indicador_base', (string) $indicator->id,
                null, ['revision' => 1, 'vinculo_id' => $binding->id]);

            return [$indicator, $binding];
        });

        return response()->json(['data' => $this->presentBinding(...$indicator)], 201);
    }

    /** A teacher's own objective belongs to one assigned subject, group and period. */
    public function crearGrupo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grupo_token' => ['required', 'string'], 'materia_token' => ['required', 'string'],
            'periodo_token' => ['required', 'string'], 'texto' => ['required', 'string', 'max:2000'],
        ]);
        $text = trim($data['texto']);
        abort_if($text === '', 422, 'Escribe el indicador.');
        [$group, $subject, $period] = $this->context($data);
        abort_if(IndicatorPolicy::forYear($group->ano_lectivo_id)['alcance'] === 'grado', 409,
            'Este año usa indicadores por grado.');
        abort_unless($group->estado === Grupo::ESTADO_ACTIVO, 422, 'El grupo no está activo.');
        $this->mayWriteGroup($request, $group, $subject->id);
        abort_unless($period->estado === Periodo::ESTADO_ABIERTO, 422, 'El período no está abierto.');
        abort_if(AnoLectivo::findOrFail($group->ano_lectivo_id)->estaCerrado(), 422, 'El año está cerrado.');

        $indicator = DB::transaction(function () use ($request, $group, $subject, $period, $text): array {
            $year = AnoLectivo::whereKey($group->ano_lectivo_id)->lockForUpdate()->firstOrFail();
            abort_if($year->estaCerrado(), 422, 'El año está cerrado.');
            abort_unless($period->fresh()->estado === Periodo::ESTADO_ABIERTO, 422, 'El período no está abierto.');
            $this->mayWriteGroup($request, $group, $subject->id);
            $base = IndicadorBase::create([
                'texto' => $text, 'orden' => 1, 'estado' => 'activo', 'version' => 1,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $revisionId = DB::table('indicadores_revisiones')->insertGetId([
                'indicador_id' => $base->id, 'version' => 1, 'texto' => $text,
                'actor_id' => $request->user()->id, 'created_at' => now(),
            ]);
            $binding = IndicadorVinculo::create([
                'indicador_id' => $base->id, 'revision_id' => $revisionId,
                'ano_lectivo_id' => $group->ano_lectivo_id, 'grado_id' => $group->grado_id,
                'grupo_id' => $group->id, 'materia_id' => $subject->id,
                'periodos_orden' => [$period->orden], 'estado' => 'activo', 'version' => 1,
                'created_by' => $request->user()->id,
            ]);
            AuditLogger::tenant($request->user(), 'CREATE', 'indicador_grupo', (string) $binding->id,
                null, ['grupo_id' => $group->id, 'materia_id' => $subject->id, 'periodo_orden' => $period->orden]);

            return [$base, $binding];
        });

        return response()->json(['data' => $this->presentBinding(...$indicator)], 201);
    }

    public function revisar(Request $request, string $indicatorToken): JsonResponse
    {
        $this->mayManage($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'texto' => ['required', 'string', 'max:2000'],
            'orden' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $text = trim($data['texto']);
        abort_if($text === '', 422, 'Escribe el indicador.');
        $indicator = $this->indicator($indicatorToken);
        abort_if(IndicadorVinculo::where('indicador_id', $indicator->id)->whereNotNull('grupo_id')->exists(), 403);
        DB::transaction(function () use ($request, $indicator, $data, $text): void {
            $locked = IndicadorBase::whereKey($indicator->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $data['version'], 409, 'El indicador cambió. Recarga.');
            $locked->update(['texto' => $text, 'orden' => $data['orden'], 'version' => $locked->version + 1,
                'updated_by' => $request->user()->id]);
            DB::table('indicadores_revisiones')->insert([
                'indicador_id' => $locked->id, 'version' => $locked->version,
                'texto' => $text, 'actor_id' => $request->user()->id, 'created_at' => now(),
            ]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'indicador_base', (string) $locked->id,
                ['version' => $data['version']], ['version' => $locked->version]);
        });

        return response()->json(['data' => [
            'indicador_token' => $indicatorToken, 'version' => $indicator->fresh()->version,
            'texto' => $text, 'nota' => 'Los vínculos existentes conservan su revisión hasta actualizarse expresamente.',
        ]]);
    }

    public function vincular(Request $request, string $indicatorToken): JsonResponse
    {
        $this->mayManage($request);
        $data = $this->bindingInput($request, false);
        [$year, $grade, $subject, $periodOrders] = $this->bindingContext($data);
        $indicator = $this->indicator($indicatorToken);
        abort_if(IndicadorVinculo::where('indicador_id', $indicator->id)->whereNotNull('grupo_id')->exists(), 403);
        $binding = DB::transaction(function () use ($request, $indicator, $year, $grade, $subject, $periodOrders) {
            AnoLectivo::whereKey($year->id)->lockForUpdate()->firstOrFail();
            abort_if(IndicadorVinculo::where('indicador_id', $indicator->id)->where('ano_lectivo_id', $year->id)
                ->where('grado_id', $grade->id)->where('materia_id', $subject->id)->exists(), 409,
                'El indicador ya está vinculado a esa asignatura y grado.');
            $revision = DB::table('indicadores_revisiones')->where('indicador_id', $indicator->id)
                ->where('version', $indicator->version)->first();
            $binding = IndicadorVinculo::create([
                'indicador_id' => $indicator->id, 'revision_id' => $revision->id,
                'ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'materia_id' => $subject->id,
                'periodos_orden' => $periodOrders, 'estado' => 'activo', 'version' => 1,
                'created_by' => $request->user()->id,
            ]);
            AuditLogger::tenant($request->user(), 'CREATE', 'indicador_vinculo', (string) $binding->id,
                null, ['revision' => $revision->version]);

            return $binding;
        });

        return response()->json(['data' => $this->presentBinding($indicator, $binding)], 201);
    }

    public function actualizarVinculo(Request $request, string $bindingToken): JsonResponse
    {
        $this->mayManage($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'periodos_orden' => ['nullable', 'array', 'max:12'],
            'periodos_orden.*' => ['integer', 'between:1,12', 'distinct'],
            'usar_revision_actual' => ['required', 'boolean'],
        ]);
        $binding = $this->binding($bindingToken);
        abort_if($binding->grupo_id !== null, 403);
        $year = AnoLectivo::findOrFail($binding->ano_lectivo_id);
        abort_if($year->estaCerrado(), 422, 'El año está cerrado.');
        $orders = $this->validOrders($year, $data['periodos_orden'] ?? []);
        DB::transaction(function () use ($request, $binding, $data, $orders): void {
            AnoLectivo::whereKey($binding->ano_lectivo_id)->lockForUpdate()->firstOrFail();
            $locked = IndicadorVinculo::whereKey($binding->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $data['version'], 409, 'El vínculo cambió. Recarga.');
            abort_if(Periodo::where('ano_lectivo_id', $locked->ano_lectivo_id)->where('estado', 'cerrado')->exists(), 422,
                'Hay períodos cerrados: este vínculo histórico no se puede cambiar.');
            $next = ['periodos_orden' => $orders, 'version' => $locked->version + 1];
            if ($data['usar_revision_actual']) {
                $next['revision_id'] = DB::table('indicadores_revisiones')
                    ->where('indicador_id', $locked->indicador_id)->orderByDesc('version')->value('id');
            }
            $locked->update($next);
            AuditLogger::tenant($request->user(), 'UPDATE', 'indicador_vinculo', (string) $locked->id,
                ['version' => $data['version']], ['version' => $locked->version]);
        });

        return response()->json(['data' => $this->presentBinding(IndicadorBase::findOrFail($binding->indicador_id), $binding->fresh())]);
    }

    public function editarTextoGrado(Request $request, string $bindingToken): JsonResponse
    {
        $this->mayManage($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'texto' => ['required', 'string', 'max:2000'],
        ]);
        $text = trim($data['texto']);
        abort_if($text === '', 422, 'Escribe el indicador.');
        $binding = $this->binding($bindingToken);
        abort_unless($binding->grupo_id === null && $binding->estado === 'activo', 403);
        abort_unless(IndicatorPolicy::forYear($binding->ano_lectivo_id)['alcance'] === 'grado', 409,
            'Este año no usa indicadores por grado.');
        DB::transaction(function () use ($request, $binding, $data, $text): void {
            $year = AnoLectivo::whereKey($binding->ano_lectivo_id)->lockForUpdate()->firstOrFail();
            abort_if($year->estaCerrado(), 422, 'El año está cerrado.');
            $locked = IndicadorVinculo::whereKey($binding->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $data['version'], 409, 'El indicador cambió. Recarga.');
            abort_if(Periodo::where('ano_lectivo_id', $year->id)->where('estado', 'cerrado')
                ->when($locked->periodos_orden !== null, fn ($query) => $query->whereIn('orden', $locked->periodos_orden))
                ->exists(), 422, 'No se edita un indicador que aparece en un período cerrado.');
            $base = IndicadorBase::whereKey($locked->indicador_id)->lockForUpdate()->firstOrFail();
            $newVersion = $base->version + 1;
            $revisionId = DB::table('indicadores_revisiones')->insertGetId([
                'indicador_id' => $base->id, 'version' => $newVersion, 'texto' => $text,
                'actor_id' => $request->user()->id, 'created_at' => now(),
            ]);
            $base->update(['texto' => $text, 'version' => $newVersion, 'updated_by' => $request->user()->id]);
            $locked->update(['revision_id' => $revisionId, 'version' => $locked->version + 1]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'indicador_vinculo', (string) $locked->id,
                ['version' => $data['version']], ['version' => $locked->version, 'revision' => $newVersion]);
        });

        $binding = $binding->fresh();

        return response()->json(['data' => $this->presentBinding(IndicadorBase::findOrFail($binding->indicador_id), $binding)]);
    }

    public function variante(Request $request, string $bindingToken): JsonResponse
    {
        $data = $request->validate([
            'grupo_token' => ['required', 'string'], 'periodo_orden' => ['required', 'integer', 'between:0,12'],
            'texto' => ['required', 'string', 'max:2000'], 'version' => ['required', 'integer', 'min:0'],
        ]);
        $text = trim($data['texto']);
        abort_if($text === '', 422, 'Escribe la variante.');
        $binding = $this->binding($bindingToken);
        $group = $this->group($data['grupo_token']);
        abort_if(IndicatorPolicy::forYear($group->ano_lectivo_id)['alcance'] === 'grado', 409,
            'Este año usa indicadores por grado.');
        abort_unless($binding->ano_lectivo_id === $group->ano_lectivo_id && $binding->grado_id === $group->grado_id, 422,
            'El grupo no pertenece al ámbito del indicador.');
        abort_unless($binding->grupo_id === null || $binding->grupo_id === $group->id, 403);
        $this->mayWriteGroup($request, $group, $binding->materia_id);
        $this->assertOpenScope($binding, $data['periodo_orden']);
        $version = DB::transaction(function () use ($request, $binding, $group, $data, $text): int {
            AnoLectivo::whereKey($group->ano_lectivo_id)->lockForUpdate()->firstOrFail();
            $this->assertOpenScope($binding, $data['periodo_orden']);
            $current = DB::table('indicadores_variantes')->where('vinculo_id', $binding->id)
                ->where('grupo_id', $group->id)->where('periodo_orden', $data['periodo_orden'])->lockForUpdate()->first();
            abort_unless(($current?->version ?? 0) === $data['version'], 409, 'La variante cambió. Recarga.');
            $nextVersion = $data['version'] + 1;
            $values = ['texto' => $text, 'version' => $nextVersion, 'updated_by' => $request->user()->id, 'updated_at' => now()];
            if ($current) {
                DB::table('indicadores_variantes')->where('id', $current->id)->update($values);
            } else {
                DB::table('indicadores_variantes')->insert([
                    'vinculo_id' => $binding->id, 'grupo_id' => $group->id,
                    'periodo_orden' => $data['periodo_orden'], ...$values, 'created_at' => now(),
                ]);
            }
            AuditLogger::tenant($request->user(), $current ? 'UPDATE' : 'CREATE', 'indicador_variante', (string) $binding->id,
                $current ? ['version' => $current->version] : null,
                ['grupo_id' => $group->id, 'periodo_orden' => $data['periodo_orden'], 'version' => $nextVersion]);

            return $nextVersion;
        });

        return response()->json(['data' => ['vinculo_token' => $bindingToken, 'grupo_token' => $data['grupo_token'],
            'periodo_orden' => $data['periodo_orden'], 'texto' => $text, 'version' => $version]]);
    }

    private function bindingInput(Request $request, bool $withText = true): array
    {
        return $request->validate([
            'ano_token' => ['required', 'string'], 'grado_token' => ['required', 'string'],
            'materia_token' => ['required', 'string'],
            'periodos_orden' => ['nullable', 'array', 'max:12'],
            'periodos_orden.*' => ['integer', 'between:1,12', 'distinct'],
            'texto' => [$withText ? 'required' : 'prohibited', 'string', 'max:2000'],
            'orden' => [$withText ? 'required' : 'prohibited', 'integer', 'min:1', 'max:1000'],
        ]);
    }

    private function bindingContext(array $data, bool $writing = true): array
    {
        $year = $this->year($data['ano_token']);
        abort_if($writing && $year->estaCerrado(), 422, 'El año está cerrado.');
        $grade = OpaqueUrlToken::find('grado', $data['grado_token'], Grado::where('ano_lectivo_id', $year->id));
        $subject = OpaqueUrlToken::find('materia', $data['materia_token'], Materia::where('ano_lectivo_id', $year->id));
        abort_unless($grade && $subject && $grade->estado === 'activo' && $subject->estado === 'activo', 422,
            'Selecciona un grado y una asignatura activos del año.');
        abort_unless(DB::table('materias_curriculares')->where('ano_lectivo_id', $year->id)
            ->where('grado_id', $grade->id)->where('materia_id', $subject->id)->exists(), 422,
            'La asignatura no pertenece al currículo de ese grado.');

        $orders = $this->validOrders($year, $data['periodos_orden'] ?? []);
        abort_if($writing && Periodo::where('ano_lectivo_id', $year->id)
            ->when($orders !== null, fn ($query) => $query->whereIn('orden', $orders))
            ->where('estado', 'cerrado')->exists(), 422,
            'Un indicador nuevo no puede incorporarse retrospectivamente a períodos cerrados. Selecciona solo períodos futuros.');

        return [$year, $grade, $subject, $orders];
    }

    private function validOrders(AnoLectivo $year, array $orders): ?array
    {
        if ($orders === []) {
            return null;
        }
        $known = Periodo::where('ano_lectivo_id', $year->id)->whereIn('orden', $orders)->count();
        abort_unless($known === count($orders), 422, 'Selecciona períodos reales de ese año.');
        sort($orders);

        return $orders;
    }

    private function context(array $data): array
    {
        $group = $this->group($data['grupo_token']);
        $subject = OpaqueUrlToken::find('materia', $data['materia_token'], Materia::where('ano_lectivo_id', $group->ano_lectivo_id));
        $period = OpaqueUrlToken::find('periodo', $data['periodo_token'], Periodo::where('ano_lectivo_id', $group->ano_lectivo_id));
        abort_unless($subject && $period, 422, 'La materia y el período deben pertenecer al año del grupo.');
        GroupSubjectScope::assert($group, $subject);

        return [$group, $subject, $period];
    }

    private function assertOpenScope(IndicadorVinculo $binding, int $order): void
    {
        abort_unless($order === 0 || $this->applies($binding, $order), 422, 'El período no aplica al indicador.');
        $periods = Periodo::where('ano_lectivo_id', $binding->ano_lectivo_id)
            ->when($order !== 0, fn ($query) => $query->where('orden', $order))->get();
        abort_if($periods->isEmpty() || $periods->contains(fn (Periodo $period) => $period->estaCerrado()), 422,
            'No puedes modificar una variante que afecta un período cerrado o inexistente.');
        abort_if(AnoLectivo::findOrFail($binding->ano_lectivo_id)->estaCerrado(), 422, 'El año está cerrado.');
    }

    private function applies(IndicadorVinculo $binding, int $order): bool
    {
        return $binding->estado === 'activo'
            && ($binding->periodos_orden === null || in_array($order, $binding->periodos_orden, true));
    }

    private function mayManage(Request $request): void
    {
        abort_unless($request->user()->can('indicadores.base.gestionar'), 403);
    }

    private function mayReadGroup(Request $request, Grupo $group, Materia $subject): void
    {
        if ($request->user()->can('indicadores.base.gestionar')) {
            return;
        }
        abort_unless($request->user()->can('indicadores.grupo.ver')
            && $this->assigned($request, $group, $subject->id), 403);
    }

    private function mayWriteGroup(Request $request, Grupo $group, int $subjectId): void
    {
        if ($request->user()->can('indicadores.base.gestionar')) {
            return;
        }
        abort_unless($request->user()->can('indicadores.grupo.editar'), 403);
        abort_unless(IndicatorPolicy::forYear($group->ano_lectivo_id)['docente_puede_editar'], 403);
        abort_unless($this->assigned($request, $group, $subjectId), 403);
    }

    private function assigned(Request $request, Grupo $group, int $subjectId): bool
    {
        return AsignacionDocente::where('ano_lectivo_id', $group->ano_lectivo_id)
            ->where('grupo_id', $group->id)->where('materia_id', $subjectId)
            ->where('docente_id', $request->user()->id)->whereNull('deleted_at')->exists();
    }

    private function presentBinding(IndicadorBase $indicator, IndicadorVinculo $binding): array
    {
        $revision = DB::table('indicadores_revisiones')->where('id', $binding->revision_id)->first();

        return ['indicador_token' => OpaqueUrlToken::for('indicador-base', $indicator->id),
            'vinculo_token' => OpaqueUrlToken::for('indicador-vinculo', $binding->id),
            'texto_base' => $indicator->texto, 'version_base' => (int) $indicator->version,
            'orden' => (int) $indicator->orden,
            'texto_vinculado' => $revision->texto, 'revision_vinculada' => (int) $revision->version,
            'periodos_orden' => $binding->periodos_orden, 'version_vinculo' => (int) $binding->version];
    }

    private function year(string $token): AnoLectivo
    {
        return OpaqueUrlToken::find('ano-lectivo', $token, AnoLectivo::query()) ?? abort(404);
    }

    private function group(string $token): Grupo
    {
        return OpaqueUrlToken::find('grupo', $token, Grupo::query()) ?? abort(404);
    }

    private function indicator(string $token): IndicadorBase
    {
        return OpaqueUrlToken::find('indicador-base', $token, IndicadorBase::query()) ?? abort(404);
    }

    private function binding(string $token): IndicadorVinculo
    {
        return OpaqueUrlToken::find('indicador-vinculo', $token, IndicadorVinculo::query()) ?? abort(404);
    }
}
