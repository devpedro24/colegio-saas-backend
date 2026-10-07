<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AulaAdjunto;
use App\Models\Academico\Aula;
use App\Models\Academico\AulaEntrega;
use App\Models\Academico\AulaRecurso;
use App\Models\StoredFile;
use App\Services\AulaAccess;
use App\Services\AulaContentPolicy;
use App\Services\AulaOfficeViewer;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken as Token;
use App\Support\Storage\StorageException;
use App\Support\Storage\AulaStoragePath;
use App\Support\Storage\StorageService;
use App\Support\Storage\StoredFilePublicToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AulaArchivoController extends Controller
{
    public function __construct(private readonly AulaAccess $access, private readonly StorageService $storage,
        private readonly AulaOfficeViewer $officeViewer) {}

    public function subirPortada(Request $request, string $token): JsonResponse
    {
        $aula = Token::find('aula', $token, Aula::query());
        abort_unless($aula, 404);
        $this->access->manages($request->user(), $aula);
        $this->access->manages($request->user(), $aula, 'aula.archivos.gestionar');
        $data = $request->validate(['archivo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120']]);
        try {
            $stored = $this->storage->store($data['archivo'], AulaStoragePath::classroom($aula), (string) $request->user()->email);
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
        $this->access->content($request->user(), $resource->seccion->aula, 'editar', $resource->tipo === 'cuestionario');
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.archivos.gestionar');
        app(AulaContentPolicy::class)->assertEditable($resource->seccion->periodo);
        $data = $request->validate(['archivo' => ['required', 'file',
            'max:'.intdiv((int) config('storage.max_file_bytes'), 1024)]]);
        abort_if(AulaAdjunto::where('recurso_id', $resource->id)->count() >= 30, 422,
            'Cada recurso admite hasta 30 archivos.');
        try {
            $stored = $this->storage->store($data['archivo'], AulaStoragePath::resource($resource), (string) $request->user()->email);
        } catch (StorageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $attachment = AulaAdjunto::create(['recurso_id' => $resource->id, 'archivo_token' => StoredFilePublicToken::for($stored),
            'nombre' => $data['archivo']->getClientOriginalName(), 'autor_id' => $request->user()->id]);
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
        abort_if($submission->nota !== null || $submission->estado === 'revisada', 422, 'La entrega ya fue revisada o calificada.');
        abort_if($submission->estado !== 'borrador'
            && ! ($submission->estado === 'entregada' && ($resource->configuracion['reenvios'] ?? false)), 422,
            'La entrega ya fue enviada y no admite cambios de archivos.');
        abort_if($resource->fecha_limite && now('UTC')->gt($resource->fecha_limite)
            && ! ($resource->configuracion['entrega_tardia'] ?? false), 422, 'La fecha límite ya pasó.');
        $data = $request->validate(['archivo' => ['required', 'file']]);
        try {
            $stored = $this->storage->store($data['archivo'], AulaStoragePath::submission($submission, $request->user()), (string) $request->user()->email);
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
        $safeName = str_replace(["\r", "\n", '"', '\\'], '_', $attachment->nombre) ?: 'archivo';
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
        abort_unless($stored && in_array($stored->mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)
            && Storage::disk($stored->disk)->exists($stored->path), 404);

        return response()->file(Storage::disk($stored->disk)->path($stored->path),
            ['Content-Type' => $stored->mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function verMedio(Request $request, string $token): BinaryFileResponse
    {
        $attachment = Token::find('aula-adjunto', $token, AulaAdjunto::whereNotNull('recurso_id'));
        abort_unless($attachment, 404);
        $resource = AulaRecurso::findOrFail($attachment->recurso_id);
        $this->access->readResource($request->user(), $resource);
        $stored = StoredFilePublicToken::findForTenant((string) tenant()->id, $attachment->archivo_token);
        abort_unless($stored && in_array($stored->mime,
            ['application/pdf', 'audio/mpeg', 'audio/ogg', 'video/mp4', 'video/webm'], true)
            && Storage::disk($stored->disk)->exists($stored->path), 404);

        return response()->file(Storage::disk($stored->disk)->path($stored->path),
            ['Content-Type' => $stored->mime, 'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function verOficina(Request $request, string $token): JsonResponse
    {
        $attachment = Token::find('aula-adjunto', $token, AulaAdjunto::whereNotNull('recurso_id'));
        abort_unless($attachment, 404);
        $resource = AulaRecurso::findOrFail($attachment->recurso_id);
        $this->access->readResource($request->user(), $resource);
        $stored = StoredFilePublicToken::findForTenant((string) tenant()->id, $attachment->archivo_token);
        abort_unless($stored && AulaOfficeViewer::supports($stored->mime)
            && Storage::disk($stored->disk)->exists($stored->path), 404);

        try {
            $configuration = $this->officeViewer->configuration($stored, $attachment->nombre);
        } catch (\Throwable $error) {
            report($error);

            return response()->json(['message' => 'El visor Office autoalojado no está disponible. El archivo original sigue guardado y puedes descargarlo.'], 503);
        }

        return response()->json(['data' => $configuration], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function quitarAdjunto(Request $request, string $token): JsonResponse
    {
        $attachment = Token::find('aula-adjunto', $token, AulaAdjunto::query());
        abort_unless($attachment, 404);
        if ($attachment->recurso_id) {
            $resource = AulaRecurso::findOrFail($attachment->recurso_id);
            $this->access->content($request->user(), $resource->seccion->aula, 'editar', $resource->tipo === 'cuestionario');
            $this->access->manages($request->user(), $resource->seccion->aula, 'aula.archivos.gestionar');
            app(AulaContentPolicy::class)->assertEditable($resource->seccion->periodo);
        } else {
            $submission = AulaEntrega::findOrFail($attachment->entrega_id);
            $resource = $submission->recurso;
            $this->access->readResource($request->user(), $resource);
            abort_unless($request->user()->can('aula.entregas.enviar')
                && $this->access->enrollment($request->user(), $resource->seccion->aula)?->id === $submission->matricula_id, 403);
            abort_if($submission->nota !== null || $submission->estado === 'revisada' || $resource->estado === 'cerrado'
                || $resource->seccion->periodo->estaCerrado()
                || ($resource->disponible_hasta && now('UTC')->gt($resource->disponible_hasta)), 422,
                'Esta entrega ya no admite cambios.');
            abort_if($submission->estado !== 'borrador'
                && ! ($submission->estado === 'entregada' && ($resource->configuracion['reenvios'] ?? false)), 422,
                'La entrega ya fue enviada y no admite cambios de archivos.');
            abort_if($submission->estado === 'entregada' && trim((string) $submission->texto) === ''
                && AulaAdjunto::where('entrega_id', $submission->id)->count() <= 1, 422,
                'No puedes quitar el último archivo de una entrega sin texto.');
        }

        $before = ['recurso_id' => $attachment->recurso_id, 'entrega_id' => $attachment->entrega_id,
            'archivo_token' => $attachment->archivo_token,
            'nombre' => $attachment->nombre];
        AuditLogger::tenant($request->user(), 'DELETE', 'aula_adjunto', (string) $attachment->id, $before, null);
        // Una duplicación de año puede reutilizar el mismo archivo almacenado.
        // Se retira solo este vínculo; la purga física tiene su propio ciclo de retención.
        $attachment->delete();

        return response()->json(['data' => ['eliminado' => true]]);
    }

    public static function present(AulaAdjunto $attachment): array
    {
        $stored = StoredFilePublicToken::findForTenant((string) tenant()->id, $attachment->archivo_token);

        return self::presentWithStored($attachment, $stored);
    }

    public static function presentWithStored(AulaAdjunto $attachment, ?StoredFile $stored): array
    {

        return ['token' => Token::for('aula-adjunto', $attachment->id), 'nombre' => $attachment->nombre,
            'mime' => $stored?->mime,
            'es_imagen' => $attachment->recurso_id !== null
                && $stored && in_array($stored->mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)];
    }
}
