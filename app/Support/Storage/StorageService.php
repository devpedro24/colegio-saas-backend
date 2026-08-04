<?php

declare(strict_types=1);

namespace App\Support\Storage;

use App\Models\StoredFile;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Pipeline UNICO de archivos por tenant (RN-AC-001..006, D-STORAGE).
 *
 * Todo archivo que suba la plataforma (matricula, boletines, observador,
 * comunicados, tareas, eventos...) pasa por aqui: whitelist MIME, tamano
 * maximo, antivirus, cuota por plan, deduplicacion por checksum y descarga
 * con URL firmada de corta vida.
 *
 * Los BYTES se guardan en el disco 'tenant' bajo
 *   storage/app/tenants/<tenant_id>/<carpeta>/<uuid>.<ext>
 * (aislamiento de almacenamiento, RN-AC-001); el METADATO se registra en la
 * BD central (`stored_files`) para resolver cuotas y descargas firmadas.
 */
final class StorageService
{
    public function __construct(private readonly FileScanner $scanner)
    {
    }

    /**
     * Guarda un archivo en el storage aislado del colegio y registra su metadato.
     *
     * @throws StorageException ante whitelist/tamano/antivirus/cuota fallidas.
     */
    public function store(UploadedFile $file, string $folder = 'generales', ?string $uploadedByEmail = null, ?Tenant $tenant = null): StoredFile
    {
        $tenant ??= $this->currentTenant();

        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $this->assertMimeAllowed($mime);
        $this->assertSizeAllowed($file);

        $checksum = hash_file('sha256', $file->getRealPath());

        // Deduplicacion: mismo colegio + mismo contenido no ocupa doble cuota.
        $existing = StoredFile::query()
            ->where('tenant_id', $tenant->id)
            ->where('checksum', $checksum)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->assertQuota($tenant, $file->getSize());

        $extension = $this->extensionFor($mime);
        $path = trim($folder, '/').'/'.Str::uuid().'.'.$extension;

        // 1. Guardar bytes (aislados por tenant).
        try {
            Storage::disk(config('storage.disk'))->putFileAs(
                $tenant->id.'/'.$path,
                $file,
                pathinfo($path, PATHINFO_BASENAME),
            );
        } catch (Throwable $e) {
            throw new StorageException('No se pudo almacenar el archivo. Intente nuevamente.');
        }

        $fullPath = $tenant->id.'/'.$path;

        // 2. Antivirus (stub aprobador por defecto; ClamAV al enchufar).
        try {
            $this->scanner->scan(config('storage.disk'), $fullPath);
        } catch (StorageException $e) {
            Storage::disk(config('storage.disk'))->delete($fullPath);
            throw $e;
        }

        // 3. Metadato central.
        return StoredFile::create([
            'tenant_id' => (string) $tenant->id,
            'disk' => (string) config('storage.disk'),
            'path' => $fullPath,
            'mime' => $mime,
            'size' => $file->getSize(),
            'checksum' => $checksum,
            'original_name' => $file->getClientOriginalName(),
            'uploaded_by_email' => $uploadedByEmail,
        ]);
    }

    /**
     * URL firmada de corta vida para descargar el archivo (RN-AC-004).
     */
    public function signedUrl(StoredFile $file, ?int $minutes = null): string
    {
        $ttl = $minutes ?? (int) config('storage.signed_url_minutes', 15);

        return URL::temporarySignedRoute(
            'storage.file',
            now()->addMinutes($ttl),
            ['file' => $file->id, 'tenant' => $file->tenant_id],
        );
    }

    /**
     * Cuota en bytes del plan del colegio (D-STORAGE).
     */
    public function quotaBytes(Tenant $tenant): int
    {
        $gb = (int) (config("storage.quotas_gb.{$tenant->plan}")
            ?? config('storage.quotas_gb.esencial'));

        return $gb * 1024 * 1024 * 1024;
    }

    private function currentTenant(): Tenant
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        if (! $tenant instanceof Tenant) {
            throw new StorageException('No hay un colegio activo para almacenar archivos.');
        }

        return $tenant;
    }

    private function assertMimeAllowed(string $mime): void
    {
        if (! array_key_exists($mime, (array) config('storage.mime_whitelist'))) {
            throw new StorageException("El tipo de archivo ({$mime}) no esta permitido.");
        }
    }

    private function assertSizeAllowed(UploadedFile $file): void
    {
        $max = (int) config('storage.max_file_bytes');

        if ($file->getSize() > $max) {
            throw new StorageException('El archivo supera el tamano maximo permitido.');
        }
    }

    private function assertQuota(Tenant $tenant, int $newBytes): void
    {
        $used = StoredFile::usedBytesFor((string) $tenant->id);
        $quota = $this->quotaBytes($tenant);

        if ($used + $newBytes > $quota) {
            throw new StorageException('El colegio alcanzo la cuota de almacenamiento de su plan.');
        }
    }

    private function extensionFor(string $mime): string
    {
        $map = (array) config('storage.mime_whitelist');

        return (string) ($map[$mime][0] ?? 'bin');
    }
}