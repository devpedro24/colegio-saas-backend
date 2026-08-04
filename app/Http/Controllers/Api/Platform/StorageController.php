<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Storage;

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
    public function __construct(private readonly StorageService $storage)
    {
    }

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

    public function download(Request $request, string $file): JsonResponse|BinaryFileResponse
    {
        $stored = StoredFile::find($file);

        if ($stored === null || (string) $request->query('tenant', '') !== (string) $stored->tenant_id) {
            abort(404, 'Archivo no encontrado.');
        }

        $disk = Storage::disk($stored->disk);

        if (! $disk->exists($stored->path)) {
            abort(404, 'Archivo no encontrado.');
        }

        return response()->file($disk->path($stored->path), [
            'Content-Type' => $stored->mime,
            'Content-Disposition' => 'inline; filename="'.basename($stored->path).'"',
        ]);
    }
}