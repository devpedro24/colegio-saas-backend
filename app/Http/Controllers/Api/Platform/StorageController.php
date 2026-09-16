<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pipeline de archivos por tenant (RN-AC-001..006).
 *
 * upload(): sube un archivo al storage aislado del colegio validando MIME,
 * tamano, antivirus y cuota por plan. Vive en las rutas compartidas del tenant
 * (subdominio o X-Tenant), por eso se resuelve el colegio del contexto.
 *
 * download(): sirve el archivo con URL FIRMADA de corta vida. Ruta central
 * (middleware 'signed'), funciona desde cualquier origen; la firma incluye el
 * `tenant` para impedir descargas cruzadas (RN-AC-004, RN-AI-001).
 */
class StorageController extends Controller
{
    public function __construct(private readonly StorageService $storage) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file'],
            'folder' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-_\/]+$/'],
        ]);

        try {
            $stored = $this->storage->store(
                $data['file'],
                $data['folder'] ?? 'generales',
                (string) $request->user()?->email,
            );
        } catch (StorageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLogger::tenant(
            $request->user(),
            'CREATE',
            'archivo',
            (string) $stored->id,
            null,
            [
                'mime' => $stored->mime,
                'size' => $stored->size,
                'checksum' => $stored->checksum,
            ],
        );

        return response()->json([
            'archivo' => [
                'id' => $stored->id,
                'mime' => $stored->mime,
                'size' => $stored->size,
                'nombre_original' => $stored->original_name,
                'url' => $this->storage->signedUrl($stored),
            ],
        ], 201);
    }

    public function destroy(Request $request, string $file): JsonResponse
    {
        /** @var Tenant|null $tenant */
        $tenant = function_exists('tenant') ? tenant() : null;
        $stored = StoredFile::query()->find($file);

        if (! $tenant instanceof Tenant || $stored === null
            || ! hash_equals((string) $tenant->id, (string) $stored->tenant_id)) {
            abort(404, 'Archivo no encontrado.');
        }

        $previous = [
            'mime' => $stored->mime,
            'size' => $stored->size,
            'checksum' => $stored->checksum,
        ];
        $this->storage->delete($stored, $tenant);
        AuditLogger::tenant(
            $request->user(), 'DELETE', 'archivo', (string) $stored->id, $previous, null,
        );

        return response()->json(['archivo' => null]);
    }

    public function download(Request $request, string $file): JsonResponse|StreamedResponse
    {
        $stored = StoredFile::find($file);

        if ($stored === null || (string) $request->query('tenant', '') !== (string) $stored->tenant_id) {
            abort(404, 'Archivo no encontrado.');
        }

        $disk = Storage::disk($stored->disk);

        if (! $disk->exists($stored->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        $stream = $disk->readStream($stored->path);
        if (! is_resource($stream)) {
            abort(404, 'Archivo no encontrado.');
        }

        $downloadName = basename(str_replace('\\', '/', $stored->original_name));
        if ($downloadName === '' || $downloadName === '.' || $downloadName === '..') {
            $downloadName = 'archivo-'.$stored->id;
        }

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $downloadName, ['Content-Type' => $stored->mime]);
    }
}
