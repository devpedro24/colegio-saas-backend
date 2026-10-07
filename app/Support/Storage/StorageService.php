<?php

declare(strict_types=1);

namespace App\Support\Storage;

use App\Models\StoredFile;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * Pipeline UNICO de archivos por tenant (RN-AC-001..006, D-STORAGE).
 *
 * Todo archivo que suba la plataforma (matricula, boletines, observador,
 * comunicados, tareas, eventos...) pasa por aqui: whitelist MIME, tamano
 * maximo, antivirus, cuota por plan, reutilizacion de duplicados exactos en
 * la misma carpeta y descarga
 * con URL firmada de corta vida.
 *
 * Los BYTES se guardan en el disco 'tenant' bajo
 *   storage/app/tenants/<colegio>_<tenant_id>/<carpeta>/<nombre>.<ext>
 * (aislamiento de almacenamiento, RN-AC-001); el METADATO se registra en la
 * BD central (`stored_files`) para resolver cuotas y descargas firmadas.
 */
final class StorageService
{
    public function __construct(private readonly FileScanner $scanner) {}

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

        // Duplicados exactos dentro de una carpeta se reutilizan; el mismo
        // documento en dos recursos conserva una copia visible en cada uno.
        $folder = trim($folder, '/');
        if (! preg_match('~\A[\p{L}\p{N}_\[\]()&+-][\p{L}\p{N}_.\[\]()&+-]*(?:/[\p{L}\p{N}_\[\]()&+-][\p{L}\p{N}_.\[\]()&+-]*)*\z~uD', $folder)) {
            throw new StorageException('La carpeta de almacenamiento no es válida.');
        }
        $extension = $this->extensionFor($mime, $file->getClientOriginalExtension());
        $directory = self::tenantFolder($tenant).'/'.$folder;
        $baseName = $this->safeFilename($file->getClientOriginalName(), $extension,
            $this->filenameBudget($directory));

        // Locks avoid races for a document in its destination and prevent
        // overwriting another file with the same visible name.
        try {
            return Cache::lock('stored-file-checksum:'.hash('sha256', $directory.'|'.$baseName.'|'.$checksum), 120)->block(30,
                function () use ($tenant, $checksum, $file, $mime, $uploadedByEmail, $directory, $baseName): StoredFile {
                    $matches = StoredFile::where('tenant_id', $tenant->id)->where('checksum', $checksum)->get();
                    $existing = $matches->first(fn (StoredFile $item) => dirname($item->path) === $directory
                        && $item->original_name === $file->getClientOriginalName());
                    if ($existing !== null) {
                        $this->scan($existing->disk, $existing->path);
                        return $existing;
                    }
                    $orphan = $matches->first(fn (StoredFile $item) => $this->isUnlinkedAulaFile($item->path));
                    if ($orphan && str_contains($directory, '/aula/')) {
                        $this->scan($orphan->disk, $orphan->path);
                        return $this->adoptUnlinkedAulaFile($orphan, $directory, $baseName,
                            $file->getClientOriginalName());
                    }
                    $this->assertQuota($tenant, $file->getSize());

                    return Cache::lock('stored-file-name:'.hash('sha256', $directory.'/'.$baseName), 120)->block(30,
                        function () use ($tenant, $checksum, $file, $mime, $uploadedByEmail, $directory, $baseName): StoredFile {
                            $diskName = (string) config('storage.disk');
                            $disk = Storage::disk($diskName);
                            $stem = pathinfo($baseName, PATHINFO_FILENAME);
                            $extension = pathinfo($baseName, PATHINFO_EXTENSION);
                            $name = $baseName;
                            for ($n = 2; $disk->exists($directory.'/'.$name); $n++) {
                                $name = $stem.'-'.$n.'.'.$extension;
                            }
                            $path = $directory.'/'.$name;
                            try {
                                if ($disk->putFileAs($directory, $file, $name) === false) {
                                    throw new RuntimeException('Storage write failed.');
                                }
                                $this->scan($diskName, $path);
                                return StoredFile::create([
                                    'tenant_id' => (string) $tenant->id,
                                    'disk' => $diskName,
                                    'path' => $path,
                                    'mime' => $mime,
                                    'size' => $file->getSize(),
                                    'checksum' => $checksum,
                                    'original_name' => $file->getClientOriginalName(),
                                    'uploaded_by_email' => $uploadedByEmail,
                                ]);
                            } catch (Throwable $error) {
                                $disk->delete($path);
                                throw $error;
                            }
                        });
                });
        } catch (StorageException $error) {
            throw $error;
        } catch (LockTimeoutException $error) {
            throw new StorageException('El almacenamiento está ocupado. Intenta nuevamente.', previous: $error);
        } catch (Throwable $e) {
            report($e);
            throw new StorageException('No se pudo almacenar el archivo. Intente nuevamente.', previous: $e);
        }
    }

    private function scan(string $disk, string $path): void
    {
        try {
            $this->scanner->scan($disk, $path);
        } catch (Throwable $e) {
            throw $e instanceof StorageException
                ? $e
                : new StorageException('El análisis de seguridad de archivos no se completó.');
        }
    }

    private function adoptUnlinkedAulaFile(StoredFile $existing, string $directory, string $baseName,
        string $originalName): StoredFile
    {
        if (! $this->isUnlinkedAulaFile($existing->path)
            || ! str_contains($directory, '/aula/')) return $existing;

        return Cache::lock('stored-file-name:'.hash('sha256', $directory.'/'.$baseName), 120)->block(30,
            function () use ($existing, $directory, $baseName, $originalName): StoredFile {
                $disk = Storage::disk($existing->disk);
                $old = $existing->path;
                if (! $disk->exists($old)) throw new StorageException('El archivo anterior no está disponible.');
                $stem = pathinfo($baseName, PATHINFO_FILENAME);
                $extension = pathinfo($baseName, PATHINFO_EXTENSION);
                $name = $baseName;
                for ($n = 2; $disk->exists($directory.'/'.$name); $n++) $name = $stem.'-'.$n.'.'.$extension;
                $target = $directory.'/'.$name;
                if (! $disk->copy($old, $target)
                    || hash_file('sha256', $disk->path($target)) !== $existing->checksum) {
                    $disk->delete($target);
                    throw new StorageException('No se pudo organizar el archivo existente.');
                }
                try {
                    $existing->update(['path' => $target, 'original_name' => $originalName]);
                } catch (Throwable $error) {
                    $disk->delete($target);
                    throw $error;
                }
                $disk->delete($old);
                return $existing;
            });
    }

    private function isUnlinkedAulaFile(string $path): bool
    {
        return str_contains($path, '/aula/archivos-sin-vinculo/')
            || str_contains($path, '/Archivos_sin_vinculo/');
    }

    /**
     * URL firmada de corta vida para descargar el archivo (RN-AC-004).
     */
    public function signedUrl(StoredFile $file, ?int $minutes = null): string
    {
        $ttl = $minutes ?? (int) config('storage.signed_url_minutes', 15);

        $school = Tenant::findOrFail($file->tenant_id);

        return URL::temporarySignedRoute(
            'storage.file',
            now()->addMinutes($ttl),
            ['file' => StoredFilePublicToken::for($file), 'school' => $school->slug],
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

    private function extensionFor(string $mime, string $originalExtension): string
    {
        $map = (array) config('storage.mime_whitelist');
        if (in_array(strtolower($originalExtension), $map[$mime] ?? [], true)) {
            return strtolower($originalExtension);
        }
        return (string) ($map[$mime][0] ?? 'bin');
    }

    public function filenameFor(string $original, string $mime, string $directory): string
    {
        return $this->safeFilename($original, $this->extensionFor($mime,
            (string) pathinfo($original, PATHINFO_EXTENSION)), $this->filenameBudget($directory));
    }

    public static function tenantFolder(Tenant $tenant): string
    {
        // The immutable ID enforces isolation; the name makes private storage
        // navigable without relying on a database identifier alone.
        return ReadableStorageName::segment($tenant->name, 'Colegio', 48).'_'.$tenant->id;
    }

    private function filenameBudget(string $directory): int
    {
        // stored_files.path is varchar(512); leave room for -2, -3, etc.
        $budget = min(180, 505 - mb_strlen($directory));
        if ($budget < 20) throw new StorageException('La ruta del archivo es demasiado larga.');
        return $budget;
    }

    private function safeFilename(string $original, string $extension, int $maxChars): string
    {
        $stem = ReadableStorageName::segment(pathinfo(str_replace('\\', '/', $original), PATHINFO_FILENAME),
            'Archivo', $maxChars - mb_strlen($extension) - 1);

        return $stem.'.'.$extension;
    }
}
