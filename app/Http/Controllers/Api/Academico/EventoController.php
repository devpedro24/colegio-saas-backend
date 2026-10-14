<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Evento;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\StoredFile;
use App\Services\EventAccess;
use App\Services\EventAgendaCache;
use App\Services\EventMailOutbox;
use App\Services\TaskCalendar;
use App\Models\Academico\EventoCorreo;
use App\Support\OpaqueUrlToken;
use App\Support\EventOpaquePresenter;
use App\Support\Audit\AuditLogger;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use App\Support\Storage\StoredFilePublicToken;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EventoController extends Controller
{
    public function __construct(private EventAccess $access, private StorageService $storage) {}

    public function catalogo(Request $request): JsonResponse
    {
        $user = $request->user();
        $rector = $this->access->rector($user);
        $teacher = $this->access->teacher($user);
        $broad = $rector || ($teacher && $this->access->unrestricted());
        $groups = $this->access->groupIds($user);
        $assignments = AsignacionDocente::where('docente_id', $user->id)->get(['grupo_id', 'materia_id']);
        $groupRows = Grupo::with('grado')->where('estado', 'activo')
            ->when(! $broad, fn ($q) => $q->whereIn('id', $groups))
            ->get(['id', 'nombre', 'grado_id', 'ano_lectivo_id']);
        $subjectRows = ($teacher || $rector)
            ? Materia::where('estado', 'activo')
                ->when(! $broad, fn ($q) => $q->whereIn('id', $assignments->pluck('materia_id')))
                ->get(['id', 'nombre']) : collect();

        if ($request->boolean('opaque')) {
            return response()->json(['data' => [
                'puede_crear' => $rector || $teacher || $user->can('eventos.publicar_institucional'),
                'es_rector' => $rector,
                'puede_institucional' => $user->can('eventos.publicar_institucional'),
                'docentes_cualquier_grupo' => $this->access->unrestricted(),
                'grupos' => $groupRows->map(fn (Grupo $group) => [
                    'url_token' => OpaqueUrlToken::for('grupo', $group->id),
                    'nombre' => $group->nombre,
                    'grado_token' => OpaqueUrlToken::for('grado', $group->grado_id),
                    'ano_lectivo_token' => OpaqueUrlToken::for('ano-lectivo', $group->ano_lectivo_id),
                    'grado' => $group->grado ? [
                        'url_token' => OpaqueUrlToken::for('grado', $group->grado->id),
                        'nombre' => $group->grado->nombre,
                    ] : null,
                ]),
                'materias' => $subjectRows->map(fn (Materia $subject) => [
                    'url_token' => OpaqueUrlToken::for('materia', $subject->id),
                    'nombre' => $subject->nombre,
                ]),
                'asignaciones' => $assignments->map(fn (AsignacionDocente $assignment) => [
                    'grupo_token' => OpaqueUrlToken::for('grupo', $assignment->grupo_id),
                    'materia_token' => OpaqueUrlToken::for('materia', $assignment->materia_id),
                ]),
            ]]);
        }

        return response()->json(['data' => [
            'puede_crear' => $rector || $teacher || $user->can('eventos.publicar_institucional'), 'es_rector' => $rector,
            'docentes_cualquier_grupo' => $this->access->unrestricted(),
            'grupos' => $groupRows,
            'materias' => $subjectRows,
            'asignaciones' => $assignments,
        ]]);
    }

    public function configurar(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('eventos.configurar'), 403);
        $data = $request->validate(['docentes_cualquier_grupo' => ['required', 'boolean']]);
        abort_if($data['docentes_cualquier_grupo'], 422, __('Los docentes solo pueden publicar para sus grupos asignados.'));
        DB::transaction(function () use ($data, $request) {
            $previous = ['docentes_cualquier_grupo' => $this->access->unrestricted()];
            DB::table('configuracion_eventos')->updateOrInsert(['id' => 1], [...$data, 'updated_at' => now()]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'configuracion_eventos', '1', $previous, $data);
        });

        return response()->json(['data' => $data]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'grupo_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if(Carbon::parse($filters['desde'])->diffInDays($filters['hasta']) > 93, 422, 'Consulta como máximo tres meses a la vez.');
        $events = $this->access->visible($request->user())->with('grupos:id,nombre')
            ->whereBetween('fecha', [$filters['desde'], $filters['hasta']])
            ->when(! empty($filters['grupo_id']), fn ($q) => $q->where(fn ($q) => $q->where('institucional', true)->orWhereHas('grupos', fn ($q) => $q->where('grupos.id', $filters['grupo_id']))))
            ->orderBy('fecha')->orderBy('hora_inicio')->orderBy('id')->paginate(200);
        $events->through(fn (Evento $event) => $request->boolean('opaque')
            ? EventOpaquePresenter::present($event)
            : [...$event->toArray(), 'url_token' => OpaqueUrlToken::for('evento', $event->id)]);

        return response()->json($events);
    }

    /** Conteos por fecha para pintar un mes sin transferir todos sus eventos. */
    public function resumen(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'grupo_id' => ['nullable', 'integer'],
            'categoria' => ['nullable', Rule::in(['actividad', 'reunion', 'celebracion', 'aviso', 'tarea'])],
        ]);
        abort_if(Carbon::parse($filters['desde'])->diffInDays($filters['hasta']) > 93, 422);
        $days = app(EventAgendaCache::class)->remember($request->user(), 'summary', $filters, function () use ($request, $filters): array {
        $rows = $this->access->visible($request->user())
            ->whereBetween('fecha', [$filters['desde'], $filters['hasta']])
            ->when(! empty($filters['categoria']), fn ($query) => $query->where('categoria', $filters['categoria']))
            ->when(! empty($filters['grupo_id']), fn ($q) => $q->where(fn ($scope) => $scope
                ->where('institucional', true)->orWhereHas('grupos', fn ($group) => $group->where('grupos.id', $filters['grupo_id']))))
            ->selectRaw('fecha, categoria, estado, COUNT(*) AS total, MIN(titulo) AS titulo')
            ->groupBy('fecha', 'categoria', 'estado')->orderBy('fecha')->get();
        $days = [];
        foreach ($rows as $row) {
            $date = (string) $row->fecha;
            $days[$date] ??= ['fecha' => $date, 'total' => 0, 'titulo_unico' => null, 'marcas' => []];
            $count = (int) $row->total;
            $days[$date]['total'] += $count;
            $days[$date]['marcas'][] = ['categoria' => $row->categoria, 'estado' => $row->estado, 'total' => $count];
            if ($count === 1) $days[$date]['titulo_unico'] = $row->titulo;
        }
        foreach ($days as &$value) {
            if ($value['total'] !== 1) $value['titulo_unico'] = null;
        }
        unset($value);

        return array_values($days);
        });

        return response()->json(['data' => $days]);
    }

    /** Primer bloque de 50 y continuaciones de 10, mediante cursor estable. */
    public function flujo(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'dia' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde', 'before_or_equal:hasta'],
            'grupo_id' => ['nullable', 'integer'], 'cursor' => ['nullable', 'string', 'max:2048'],
            'categoria' => ['nullable', Rule::in(['actividad', 'reunion', 'celebracion', 'aviso', 'tarea'])],
            'limite' => ['nullable', 'integer', Rule::in([10, 50])],
        ]);
        abort_if(Carbon::parse($filters['desde'])->diffInDays($filters['hasta']) > 93, 422);
        $limit = isset($filters['cursor']) ? 10 : 50;
        abort_if(isset($filters['limite']) && (int) $filters['limite'] !== $limit, 422);
        $result = app(EventAgendaCache::class)->remember($request->user(), 'feed', $filters, function () use ($request, $filters, $limit): array {
        $page = $this->access->visible($request->user())->with('grupos.grado')
            ->whereBetween('fecha', [$filters['desde'], $filters['hasta']])
            ->when(! empty($filters['categoria']), fn ($query) => $query->where('categoria', $filters['categoria']))
            ->when(! empty($filters['dia']), fn ($query) => $query->whereDate('fecha', $filters['dia']))
            ->when(! empty($filters['grupo_id']), fn ($query) => $query->where(fn ($scope) => $scope
                ->where('institucional', true)->orWhereHas('grupos', fn ($group) => $group->where('grupos.id', $filters['grupo_id']))))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($limit);

        return ['data' => $page->getCollection()->map(fn (Evento $event) => EventOpaquePresenter::present($event))->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode()];
        });

        return response()->json($result);
    }

    public function tareas(Request $request, TaskCalendar $calendar): JsonResponse
    {
        $filters = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'grupo_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if(Carbon::parse($filters['desde'])->diffInDays($filters['hasta']) > 93, 422);
        return response()->json($calendar->list($request->user(), $filters));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $event = $this->access->visible($request->user())->with('grupos:id,nombre')->findOrFail($id);
        $fileIds = DB::table('evento_archivos')->where('evento_id', $id)->pluck('stored_file_id');
        $files = StoredFile::where('tenant_id', tenant()->getKey())->whereIn('id', $fileIds)->get()->map(fn ($file) => [
            'url_token' => StoredFilePublicToken::for($file),
            'nombre' => $file->original_name, 'mime' => $file->mime, 'size' => $file->size,
            'url' => $this->storage->signedUrl($file),
        ]);

        $canEdit = $event->institucional
            ? $request->user()->can('eventos.publicar_institucional')
                && ($this->access->rector($request->user()) || $event->created_by === $request->user()->id)
            : $this->access->rector($request->user())
                || ($this->access->teacher($request->user()) && $event->created_by === $request->user()->id);

        if ($request->boolean('opaque')) {
            return response()->json(['data' => [...EventOpaquePresenter::present($event, $files->all(), $canEdit),
                ...($canEdit ? ['correos' => app(EventMailOutbox::class)->summary($event)] : [])]]);
        }

        return response()->json(['data' => [...$event->toArray(),
            'url_token' => OpaqueUrlToken::for('evento', $event->id),
            'archivos' => $files,
            'puede_editar' => $canEdit]]);
    }

    public function guardar(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:180'], 'descripcion' => ['required', 'string', 'max:10000'],
            'fecha' => ['required', 'date_format:Y-m-d'], 'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fin' => ['nullable', 'required_with:hora_inicio', 'date_format:H:i', 'after:hora_inicio'],
            'categoria' => ['required', Rule::in(['actividad', 'reunion', 'celebracion', 'aviso'])],
            'enviar_correo' => ['sometimes', 'boolean'], 'version' => ['sometimes', 'integer', 'min:1'],
            'institucional' => ['required', 'boolean'], 'grupo_ids' => ['array', 'max:100'], 'grupo_ids.*' => ['integer', 'distinct'],
            'materia_id' => ['nullable', 'integer', Rule::exists('materias', 'id')->whereNull('deleted_at')->where('estado', 'activo')],
        ]);
        $event = DB::transaction(function () use ($id, $data, $request) {
            $event = $id ? Evento::lockForUpdate()->findOrFail($id) : null;
            $this->access->authorizeWrite($request->user(), $data, $event);
            abort_if($event && isset($data['version']) && $event->version !== $data['version'], 409, __('El evento cambió. Recarga antes de guardar.'));
            if ($event?->estado === 'publicado') {
                $groups = $event->grupos()->pluck('grupos.id')->sort()->values()->all();
                $nextGroups = collect($data['grupo_ids'] ?? [])->sort()->values()->all();
                abort_if($groups !== $nextGroups || $event->institucional !== $data['institucional']
                    || $event->materia_id != ($data['materia_id'] ?? null)
                    || $event->enviar_correo !== ($data['enviar_correo'] ?? $event->enviar_correo),
                    422, __('El alcance y la notificación se definen antes de publicar. Crea otro evento para cambiarlos.'));
            }
            $previous = $event?->load('grupos:id,nombre')->toArray();
            $event ??= new Evento(['created_by' => $request->user()->id, 'estado' => 'borrador', 'version' => 0]);
            $event->fill(collect($data)->except(['grupo_ids', 'version'])->all());
            $event->version++;
            $event->save();
            $event->grupos()->sync($data['grupo_ids'] ?? []);
            $event->load('grupos:id,nombre');
            AuditLogger::tenant($request->user(), $id ? 'UPDATE' : 'CREATE', 'evento', (string) $event->id, $previous, $event->toArray());

            return $event;
        });

        if ($request->boolean('opaque')) {
            return response()->json(['data' => EventOpaquePresenter::present($event)], $id ? 200 : 201);
        }

        return response()->json(['data' => [...$event->toArray(),
            'url_token' => OpaqueUrlToken::for('evento', $event->id)]], $id ? 200 : 201);
    }

    public function archivo(Request $request, int $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:25600']]);
        try {
            $file = DB::transaction(function () use ($request, $id) {
                $event = Evento::with('grupos')->lockForUpdate()->findOrFail($id);
                $this->access->authorizeWrite($request->user(), [...$event->toArray(), 'grupo_ids' => $event->grupos->pluck('id')->all()], $event);
                abort_if(DB::table('evento_archivos')->where('evento_id', $id)->count() >= 10, 422, 'Máximo diez archivos por evento.');
                $file = $this->storage->store($request->file('file'), 'eventos', $request->user()->email);
                DB::table('evento_archivos')->insertOrIgnore(['evento_id' => $id, 'stored_file_id' => $file->id]);
                AuditLogger::tenant($request->user(), 'UPLOAD', 'evento', (string) $id, null, ['archivo_id' => $file->id, 'nombre' => $file->original_name]);

                return $file;
            });
        } catch (StorageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['url_token' => StoredFilePublicToken::for($file)]], 201);
    }

    public function publicar(Request $request, int $id, EventMailOutbox $mail): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $event = DB::transaction(function () use ($request, $id, $data, $mail) {
            $event = Evento::with('grupos')->lockForUpdate()->findOrFail($id);
            $this->access->authorizeWrite($request->user(), [...$event->toArray(), 'grupo_ids' => $event->grupos->pluck('id')->all()], $event);
            if ($event->estado === 'publicado') return $event;
            abort_if($event->version !== $data['version'], 409, __('El evento cambió. Recarga antes de publicar.'));
            $event->estado = 'publicado';
            $event->publicado_en = now('UTC');
            $event->version++;
            $event->save();
            $mail->prepare($event);
            AuditLogger::tenant($request->user(), 'PUBLISH', 'evento', (string) $id, null, ['enviar_correo' => $event->enviar_correo]);
            return $event;
        });
        return response()->json(['data' => EventOpaquePresenter::present($event)]);
    }

    public function reintentarCorreos(Request $request, int $id, EventMailOutbox $mail): JsonResponse
    {
        DB::transaction(function () use ($request, $id, $mail) {
            $event = Evento::with('grupos')->lockForUpdate()->findOrFail($id);
            $this->access->authorizeWrite($request->user(), [...$event->toArray(), 'grupo_ids' => $event->grupos->pluck('id')->all()], $event);
            $mail->retry($event);
            AuditLogger::tenant($request->user(), 'RETRY_MAIL', 'evento', (string) $id);
        });
        return response()->json(['data' => null]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($id, $request) {
            $event = Evento::with('grupos')->lockForUpdate()->findOrFail($id);
            $this->access->authorizeWrite($request->user(), [...$event->toArray(), 'grupo_ids' => $event->grupos->pluck('id')->all()], $event);
            AuditLogger::tenant($request->user(), 'DELETE', 'evento', (string) $id, $event->toArray());
            $event->delete();
            EventoCorreo::where('evento_id', $id)->whereIn('estado', ['pendiente', 'fallido'])
                ->update(['estado' => 'cancelado']);
        });

        return response()->json(['data' => null]);
    }
}
