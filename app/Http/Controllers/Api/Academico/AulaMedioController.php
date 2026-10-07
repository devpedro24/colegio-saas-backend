<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AulaIntento;
use App\Models\Academico\AulaPregunta;
use App\Models\Academico\AulaPreguntaMedio;
use App\Models\Academico\AulaRespuestaMedio;
use App\Services\AulaAccess;
use App\Services\AulaContentPolicy;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken as Token;
use App\Support\Storage\StorageException;
use App\Support\Storage\AulaStoragePath;
use App\Support\Storage\StorageService;
use App\Support\Storage\StoredFilePublicToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AulaMedioController extends Controller
{
    private const QUESTION_MIME = ['image/jpeg', 'image/png', 'image/webp', 'audio/mpeg',
        'audio/ogg', 'audio/webm', 'video/mp4', 'video/webm'];

    public function __construct(private readonly AulaAccess $access, private readonly StorageService $storage) {}

    public function subirPregunta(Request $request, string $token): JsonResponse
    {
        $question = Token::find('aula-pregunta', $token, AulaPregunta::query());
        abort_unless($question, 404);
        $resource = $question->recurso;
        $this->access->content($request->user(), $resource->seccion->aula, 'editar', true);
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.archivos.gestionar');
        app(AulaContentPolicy::class)->assertEditable($resource->seccion->periodo);
        abort_if(AulaIntento::where('recurso_id', $resource->id)->exists(), 422,
            'No puedes cambiar los medios de un cuestionario con intentos.');
        $data = $request->validate(['archivo' => ['required', 'file', 'max:'.intdiv((int) config('storage.max_file_bytes'), 1024),
            'mimetypes:'.implode(',', self::QUESTION_MIME)],
            'opcion_indice' => ['nullable', 'integer', 'min:0', 'max:29']]);
        $index = $data['opcion_indice'] ?? null;
        abort_if($index !== null && ! isset(($question->opciones ?? [])[$index]), 422,
            'La opción indicada no pertenece a la pregunta.');
        abort_if(AulaPreguntaMedio::where('pregunta_id', $question->id)->count() >= 12, 422,
            'Cada pregunta admite hasta 12 archivos multimedia.');
        try {
            $stored = $this->storage->store($data['archivo'], AulaStoragePath::resource($resource), (string) $request->user()->email);
        } catch (StorageException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
        abort_unless(in_array($stored->mime, self::QUESTION_MIME, true), 422, 'Tipo de medio no admitido.');
        $medium = AulaPreguntaMedio::create(['pregunta_id' => $question->id, 'opcion_indice' => $index,
            'archivo_token' => StoredFilePublicToken::for($stored), 'nombre' => $stored->original_name,
            'mime' => $stored->mime, 'autor_id' => $request->user()->id]);
        AuditLogger::tenant($request->user(), 'CREATE', 'aula_pregunta_medio', (string) $medium->id, null,
            ['pregunta_id' => $question->id, 'opcion_indice' => $index, 'nombre' => $medium->nombre]);

        return response()->json(['data' => self::presentQuestion($medium)], 201);
    }

    public function quitarPregunta(Request $request, string $token): JsonResponse
    {
        $medium = Token::find('aula-pregunta-medio', $token, AulaPreguntaMedio::query());
        abort_unless($medium, 404);
        $resource = $medium->pregunta->recurso;
        $this->access->content($request->user(), $resource->seccion->aula, 'editar', true);
        $this->access->manages($request->user(), $resource->seccion->aula, 'aula.archivos.gestionar');
        app(AulaContentPolicy::class)->assertEditable($resource->seccion->periodo);
        abort_if(AulaIntento::where('recurso_id', $resource->id)->exists(), 422,
            'No puedes cambiar los medios de un cuestionario con intentos.');
        $before = $medium->toArray();
        $medium->delete();
        AuditLogger::tenant($request->user(), 'DELETE', 'aula_pregunta_medio', (string) $medium->id, $before, null);

        return response()->json(['data' => ['eliminado' => true]]);
    }

    public function verPregunta(Request $request, string $token): BinaryFileResponse
    {
        $medium = Token::find('aula-pregunta-medio', $token, AulaPreguntaMedio::query());
        abort_unless($medium, 404);
        $question = $medium->pregunta;
        $resource = $question->recurso;
        $this->access->readResource($request->user(), $resource);
        if ($this->access->isStudent($request->user(), $resource->seccion->aula)) {
            abort_unless($request->user()->can('aula.evaluaciones.responder'), 403);
            $enrollment = $this->access->enrollment($request->user(), $resource->seccion->aula);
            $attempt = AulaIntento::where('recurso_id', $resource->id)->where('matricula_id', $enrollment?->id)
                ->latest('id')->first();
            abort_unless($attempt, 404);
            if ($attempt->estado === 'en_curso') {
                $perPage = max(1, (int) ($resource->configuracion['preguntas_por_pagina'] ?? 100));
                $ids = $attempt->presentacion['preguntas']
                    ?? $resource->preguntas()->orderBy('orden')->orderBy('id')->pluck('id')->all();
                $position = array_search($question->id, $ids, true);
                abort_if($position === false || intdiv($position, $perPage) + 1 > $attempt->pagina_actual, 404);
            }
        } else {
            abort_unless($this->access->canManage($request->user(), $resource->seccion->aula,
                'aula.evaluaciones.gestionar') || $this->access->canManage($request->user(),
                $resource->seccion->aula, 'aula.evaluaciones.calificar'), 403);
        }

        return $this->file($medium->archivo_token, $medium->mime);
    }

    public function subirRespuesta(Request $request, string $attemptToken, string $questionToken): JsonResponse
    {
        $attempt = Token::find('aula-intento', $attemptToken, AulaIntento::query());
        abort_unless($attempt, 404);
        $question = Token::find('aula-pregunta', $questionToken,
            AulaPregunta::where('recurso_id', $attempt->recurso_id));
        abort_unless($question && in_array($question->tipo, ['audio', 'video'], true), 404);
        $resource = $attempt->recurso;
        $this->access->readResource($request->user(), $resource);
        abort_unless($request->user()->can('aula.evaluaciones.responder') && $this->access->enrollment(
            $request->user(), $resource->seccion->aula)?->id === $attempt->matricula_id, 403);
        abort_if($attempt->estado !== 'en_curso' || $resource->seccion->periodo->estaCerrado()
            || $resource->estado === 'cerrado' || ($attempt->vence_at && now('UTC')->gte($attempt->vence_at))
            || ($resource->disponible_hasta && now('UTC')->gte($resource->disponible_hasta)), 422,
            'El intento ya no permite enviar respuestas.');
        $perPage = max(1, (int) ($resource->configuracion['preguntas_por_pagina'] ?? 100));
        $ids = $attempt->presentacion['preguntas']
            ?? $resource->preguntas()->orderBy('orden')->orderBy('id')->pluck('id')->all();
        $position = array_search($question->id, $ids, true);
        abort_if($position === false || intdiv($position, $perPage) + 1 > $attempt->pagina_actual, 422,
            'La pregunta todavía no está disponible.');
        $key = Token::for('aula-pregunta', $question->id);
        abort_if(! ($resource->configuracion['permitir_regresar'] ?? true)
            && intdiv($position, $perPage) + 1 < $attempt->pagina_actual, 422,
            'No puedes modificar una página anterior.');
        abort_if(! ($resource->configuracion['permitir_editar_respuestas'] ?? true)
            && array_key_exists($key, $attempt->respuestas ?? []), 422, 'Esta respuesta ya fue guardada.');
        $mimes = $question->tipo === 'audio' ? ['audio/mpeg', 'audio/ogg', 'audio/webm']
            : ['video/mp4', 'video/webm'];
        $data = $request->validate(['archivo' => ['required', 'file',
            'max:'.intdiv((int) config('storage.max_file_bytes'), 1024), 'mimetypes:'.implode(',', $mimes)]]);
        try {
            $stored = $this->storage->store($data['archivo'], AulaStoragePath::answer($attempt, $request->user()), (string) $request->user()->email);
        } catch (StorageException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
        abort_unless(in_array($stored->mime, $mimes, true), 422, 'Tipo de respuesta no admitido.');
        $medium = DB::transaction(function () use ($attempt, $question, $stored, $key, $request): AulaRespuestaMedio {
            $locked = AulaIntento::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->estado === 'en_curso' && (! $locked->vence_at || now('UTC')->lt($locked->vence_at)), 422);
            $medium = AulaRespuestaMedio::updateOrCreate(['intento_id' => $locked->id, 'pregunta_id' => $question->id],
                ['archivo_token' => StoredFilePublicToken::for($stored), 'nombre' => $stored->original_name,
                    'mime' => $stored->mime]);
            $answers = $locked->respuestas ?? [];
            $answers[$key] = Token::for('aula-respuesta-medio', $medium->id);
            $locked->update(['respuestas' => $answers, 'version' => $locked->version + 1]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'aula_respuesta_medio', (string) $medium->id, null,
                ['intento_id' => $locked->id, 'pregunta_id' => $question->id, 'nombre' => $medium->nombre]);
            return $medium;
        });

        return response()->json(['data' => ['token' => Token::for('aula-respuesta-medio', $medium->id),
            'nombre' => $medium->nombre, 'mime' => $medium->mime]], 201);
    }

    public function verRespuesta(Request $request, string $token): BinaryFileResponse
    {
        $medium = Token::find('aula-respuesta-medio', $token, AulaRespuestaMedio::query());
        abort_unless($medium, 404);
        $attempt = $medium->intento;
        $resource = $attempt->recurso;
        $this->access->readResource($request->user(), $resource);
        $own = $this->access->enrollment($request->user(), $resource->seccion->aula)?->id === $attempt->matricula_id;
        if (! $own) $this->access->manages($request->user(), $resource->seccion->aula, 'aula.evaluaciones.calificar');

        return $this->file($medium->archivo_token, $medium->mime);
    }

    public static function presentQuestion(AulaPreguntaMedio $medium): array
    {
        return ['token' => Token::for('aula-pregunta-medio', $medium->id), 'nombre' => $medium->nombre,
            'mime' => $medium->mime, 'opcion_indice' => $medium->opcion_indice];
    }

    private function file(string $fileToken, string $mime): BinaryFileResponse
    {
        $stored = StoredFilePublicToken::findForTenant((string) tenant()->id, $fileToken);
        abort_unless($stored && $stored->mime === $mime && Storage::disk($stored->disk)->exists($stored->path), 404);

        return response()->file(Storage::disk($stored->disk)->path($stored->path),
            ['Content-Type' => $mime, 'Content-Disposition' => 'inline', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff']);
    }
}
