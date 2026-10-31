<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Academico\AulaEntrega;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;
use App\Services\AulaAccess;
use App\Services\AulaContentPolicy;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use App\Support\Storage\StoredFilePublicToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class WorkDraftFileController extends Controller
{
    private function section(Request $request, string $token): AulaSeccion
    {
        $section = OpaqueUrlToken::find('aula-seccion', $token, AulaSeccion::query());
        abort_unless($section, 404);
        $access = app(AulaAccess::class);
        $access->view($request->user(), $section->aula);
        abort_unless($access->canContent($request->user(), $section->aula, 'editar')
            || $access->canContent($request->user(), $section->aula, 'crear'), 403);
        app(AulaAccess::class)->manages($request->user(), $section->aula, 'aula.archivos.gestionar');
        app(AulaContentPolicy::class)->assertEditable($section->periodo);

        return $section;
    }

    public function store(Request $request, string $sectionToken, string $token)
    {
        $section = $this->section($request, $sectionToken);

        return $this->storeFile($request, $section, $token);
    }

    private function resource(Request $request, string $token, bool $writing): AulaRecurso
    {
        $resource = OpaqueUrlToken::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource && $resource->tipo === 'tarea', 404);
        $access = app(AulaAccess::class);
        $access->readResource($request->user(), $resource);
        abort_unless($access->isStudent($request->user(), $resource->seccion->aula)
            && $request->user()->can('aula.entregas.enviar'), 403);
        if ($writing) {
            $until = $resource->disponible_hasta ?? $resource->fecha_limite;
            abort_if($resource->estado === 'cerrado' || $resource->seccion->periodo->estaCerrado()
                || ($until && now('UTC')->gt($until)), 422);
            $enrollment = $access->enrollment($request->user(), $resource->seccion->aula);
            $submission = AulaEntrega::where('recurso_id', $resource->id)->where('matricula_id', $enrollment->id)->first();
            abort_if($submission && ($submission->nota !== null || $submission->estado === 'revisada'
                || ($submission->estado !== 'borrador' && ! ($resource->configuracion['reenvios'] ?? false))), 422);
        }

        return $resource;
    }

    public function storeResponse(Request $request, string $resourceToken, string $token)
    {
        $resource = $this->resource($request, $resourceToken, true);

        return $this->storeFile($request, $resource->seccion, $token, $resource->id);
    }

    private function storeFile(Request $request, AulaSeccion $section, string $token, ?int $resourceId = null)
    {
        $data = $request->validate(['archivo' => ['required', 'file', 'max:'.intdiv((int) config('storage.max_file_bytes'), 1024)]]);
        $user = $request->user()->getKey();

        return Cache::lock('draft-files:'.tenant()->getKey().':'.$user, 120)->block(10, function () use ($request, $section, $token, $data, $user, $resourceId) {
            $existing = DB::table('work_draft_files')->where('token', $token)->first();
            if ($existing) {
                abort_unless((string) $existing->user_id === (string) $user && (int) $existing->section_id === $section->id, 404);
                abort_unless(($existing->resource_id === null ? null : (int) $existing->resource_id) === $resourceId, 404);
                abort_if(now()->gte($existing->expires_at), 410);

                return response()->json(['token' => $token])->header('Cache-Control', 'private, no-store');
            }
            $query = DB::table('work_draft_files')->where('user_id', $user)->where('expires_at', '>', now());
            abort_if((clone $query)->count() >= 50 || (clone $query)->sum('bytes') + $data['archivo']->getSize() > 100 * 1024 * 1024, 413);
            try {
                $stored = app(StorageService::class)->store($data['archivo'], 'work-drafts/'.$token, (string) $request->user()->email);
            } catch (StorageException $error) {
                return response()->json(['message' => $error->getMessage()], 422);
            }
            try {
                DB::table('work_draft_files')->insert(['token' => $token, 'user_id' => $user, 'section_id' => $section->id, 'resource_id' => $resourceId,
                    'stored_token' => StoredFilePublicToken::for($stored), 'bytes' => $stored->size, 'expires_at' => now()->addDay()]);
            } catch (\Throwable $error) {
                if (Storage::disk($stored->disk)->delete($stored->path)) {
                    $stored->forceDelete();
                }
                throw $error;
            }

            return response()->json(['token' => $token], 201)->header('Cache-Control', 'private, no-store');
        });
    }

    public function show(Request $request, string $token)
    {
        $row = DB::table('work_draft_files')->where('token', $token)->where('user_id', $request->user()->getKey())->where('expires_at', '>', now())->first();
        abort_unless($row, 404);
        $section = AulaSeccion::find($row->section_id);
        abort_unless($section, 404);
        if ($row->resource_id !== null) {
            $this->resource($request, OpaqueUrlToken::for('aula-recurso', (int) $row->resource_id), false);
        } else {
            app(AulaAccess::class)->view($request->user(), $section->aula);
            app(AulaAccess::class)->manages($request->user(), $section->aula, 'aula.archivos.gestionar');
        }
        $file = StoredFilePublicToken::findForTenant((string) tenant()->getKey(), $row->stored_token);
        abort_unless($file && Storage::disk($file->disk)->exists($file->path), 404);

        // Shared cookie/CSRF client handles the response; the file is never publicly linked.
        return response()->json(['name' => $file->original_name, 'type' => $file->mime,
            'data' => base64_encode(Storage::disk($file->disk)->get($file->path))])->header('Cache-Control', 'private, no-store');
    }

    public static function prune(): void
    {
        foreach (DB::table('work_draft_files')->where('expires_at', '<=', now())->get() as $row) {
            $file = StoredFilePublicToken::findForTenant((string) tenant()->getKey(), $row->stored_token);
            // Only the isolated temporary directory belongs to this cleanup operation.
            if ($file && str_contains($file->path, '/work-drafts/'.$row->token.'/')) {
                if (! Storage::disk($file->disk)->delete($file->path)) {
                    continue;
                }
                $file->forceDelete();
            }
            DB::table('work_draft_files')->where('token', $row->token)->delete();
        }
    }
}
