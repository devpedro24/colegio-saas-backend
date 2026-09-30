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
            return response()->json(['data' => EventOpaquePresenter::present($event, $files->all(), $canEdit)]);
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
            'categoria' => ['required', Rule::in(['actividad', 'tarea', 'reunion', 'celebracion', 'aviso'])],
            'institucional' => ['required', 'boolean'], 'grupo_ids' => ['array', 'max:100'], 'grupo_ids.*' => ['integer', 'distinct'],
            'materia_id' => ['nullable', 'integer', Rule::exists('materias', 'id')->whereNull('deleted_at')->where('estado', 'activo')],
        ]);
        $event = DB::transaction(function () use ($id, $data, $request) {
            $event = $id ? Evento::lockForUpdate()->findOrFail($id) : null;
            $this->access->authorizeWrite($request->user(), $data, $event);
            $previous = $event?->load('grupos:id,nombre')->toArray();
            $event ??= new Evento(['created_by' => $request->user()->id]);
            $event->fill(collect($data)->except('grupo_ids')->all())->save();
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

    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($id, $request) {
            $event = Evento::with('grupos')->lockForUpdate()->findOrFail($id);
            $this->access->authorizeWrite($request->user(), [...$event->toArray(), 'grupo_ids' => $event->grupos->pluck('id')->all()], $event);
            AuditLogger::tenant($request->user(), 'DELETE', 'evento', (string) $id, $event->toArray());
            $event->delete();
        });

        return response()->json(['data' => null]);
    }
}
