<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AulaAdjunto;
use App\Models\Academico\Aula;
use App\Models\Academico\AulaEntrega;
use App\Models\Academico\AulaRecurso;
use App\Services\AulaAccess;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken as Token;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use App\Support\Storage\StoredFilePublicToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AulaArchivoController extends Controller
{
    public function __construct(private readonly AulaAccess $access, private readonly StorageService $storage) {}

    public function subirPortada(Request $request, string $token): JsonResponse
    {
        $aula = Token::find('aula', $token, Aula::query());
        abort_unless($aula, 404);
        $this->access->manages($request->user(), $aula);
        $data = $request->validate(['archivo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120']]);
        try {
            $stored = $this->storage->store($data['archivo'], 'aula/portadas', (string) $request->user()->email);
        } catch (StorageException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
        $before = $aula->portada_token;
        $aula->update(['portada_token' => StoredFilePublicToken::for($stored)]);
        AuditLogger::tenant($request->user(), 'UPDATE', 'aula_portada', (string) $aula->id,
            ['archivo_token' => $before], ['archivo_token' => $aula->portada_token]);

        return response()->json(['data' => ['portada' => true]]);
    }

    public function descargarPortada(Request $request, string $token): BinaryFileResponse
    {
        $aula = Token::find('aula', $token, Aula::query());
        abort_unless($aula && $aula->portada_token, 404);
        $this->access->view($request->user(), $aula);
        $stored = StoredFilePublicToken::findForTenant((string) tenant()->id, $aula->portada_token);
        abort_unless($stored && str_starts_with($stored->mime, 'image/')
            && Storage::disk($stored->disk)->exists($stored->path), 404);

        return response()->file(Storage::disk($stored->disk)->path($stored->path),
            ['Content-Type' => $stored->mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function subirRecurso(Request $request, string $token): JsonResponse
    {
        $resource = Token::find('aula-recurso', $token, AulaRecurso::query());
        abort_unless($resource, 404);
        $this->access->manages($request->user(), $resource->seccion->aula);
        abort_if($resource->seccion->periodo->estaCerrado(), 422, 'El período está cerrado.');
        $data = $request->validate(['archivo' => ['required', 'file']]);
        try {
            $stored = $this->storage->store($data['archivo'], 'aula/materiales', (string) $request->user()->email);
        } catch (StorageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $attachment = AulaAdjunto::create(['recurso_id' => $resource->id, 'archivo_token' => StoredFilePublicToken::for($stored),
            'nombre' => $stored->original_name, 'autor_id' => $request->user()->id]);
        AuditLogger::tenant($request->user(), 'CREATE', 'aula_adjunto', (string) $attachment->id, null,
            ['recurso_id' => $resource->id, 'nombre' => $attachment->nombre]);

        return response()->json(['data' => $this->present($attachment)], 201);
    }

    public function subirEntrega(Request $request, string $token): JsonResponse
    {
        $submission = Token::find('aula-entrega', $token, AulaEntrega::query());
        abort_unless($submission, 404);
        $resource = $submission->recurso;
        $this->access->readResource($request->user(), $resource);
        abort_if($resource->estado === 'cerrado' || $resource->seccion->periodo->estaCerrado()
            || ($resource->disponible_hasta && now('UTC')->gt($resource->disponible_hasta)), 422,
            'El plazo de interacción de este recurso terminó.');
        abort_unless($request->user()->can('aula.entregas.enviar')
            && $this->access->enrollment($request->user(), $resource->seccion->aula)?->id === $submission->matricula_id, 403);
        abort_if($submission->nota !== null, 422, 'La entrega ya fue calificada.');
        abort_if($resource->fecha_limite && now('UTC')->gt($resource->fecha_limite)
            && ! ($resource->configuracion['entrega_tardia'] ?? false), 422, 'La fecha límite ya pasó.');
        $data = $request->validate(['archivo' => ['required', 'file']]);
        try {
            $stored = $this->storage->store($data['archivo'], 'aula/entregas', (string) $request->user()->email);
        } catch (StorageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $attachment = AulaAdjunto::create(['entrega_id' => $submission->id, 'archivo_token' => StoredFilePublicToken::for($stored),
            'nombre' => $stored->original_name, 'autor_id' => $request->user()->id]);
        AuditLogger::tenant($request->user(), 'CREATE', 'aula_adjunto', (string) $attachment->id, null,
            ['entrega_id' => $submission->id, 'nombre' => $attachment->nombre]);

        return response()->json(['data' => $this->present($attachment)], 201);
    }

    public function descargar(Request $request, string $token): BinaryFileResponse
    {
        $attachment = Token::find('aula-adjunto', $token, AulaAdjunto::query());
        abort_unless($attachment, 404);
        if ($attachment->recurso_id) {
            $resource = AulaRecurso::findOrFail($attachment->recurso_id);
            $this->access->readResource($request->user(), $resource);
        } else {
            $submission = AulaEntrega::findOrFail($attachment->entrega_id);
            $resource = $submission->recurso;
            $this->access->view($request->user(), $resource->seccion->aula);
            if ($this->access->enrollment($request->user(), $resource->seccion->aula)?->id !== $submission->matricula_id) {
                $this->access->manages($request->user(), $resource->seccion->aula, 'aula.entregas.calificar');
            }
        }
        $stored = StoredFilePublicToken::findForTenant((string) tenant()->id, $attachment->archivo_token);
        abort_unless($stored && Storage::disk($stored->disk)->exists($stored->path), 404);
        $safeName = preg_replace('/[\r\n"\\]/u', '_', $attachment->nombre) ?: 'archivo';
        return response()->download(Storage::disk($stored->disk)->path($stored->path), $safeName,
            ['Content-Type' => $stored->mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function verImagen(Request $request, string $token): BinaryFileResponse
    {
        $attachment = Token::find('aula-adjunto', $token, AulaAdjunto::whereNotNull('recurso_id'));
        abort_unless($attachment, 404);
        $resource = AulaRecurso::findOrFail($attachment->recurso_id);
        $this->access->readResource($request->user(), $resource);
        $stored = StoredFilePublicToken::findForTenant((string) tenant()->id, $attachment->archivo_token);
        abort_unless($stored && in_array($stored->mime, ['image/png', 'image/jpeg', 'image/webp'], true)
            && Storage::disk($stored->disk)->exists($stored->path), 404);

        return response()->file(Storage::disk($stored->disk)->path($stored->path),
            ['Content-Type' => $stored->mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function present(AulaAdjunto $attachment): array
    {
        $stored = StoredFilePublicToken::findForTenant((string) tenant()->id, $attachment->archivo_token);

        return ['token' => Token::for('aula-adjunto', $attachment->id), 'nombre' => $attachment->nombre,
            'es_imagen' => $attachment->recurso_id !== null
                && $stored && in_array($stored->mime, ['image/png', 'image/jpeg', 'image/webp'], true)];
    }
}
