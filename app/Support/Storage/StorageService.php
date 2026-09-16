<?php

declare(strict_types=1);

namespace App\Support\Storage;

use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/** Pipeline unico, aislado y deduplicado de archivos de tenant. */
final class StorageService
{
    public function __construct(private readonly FileScanner $scanner) {}

    /**
     * @throws StorageException
     */
    public function store(
        UploadedFile $file,
        string $folder = 'generales',
        ?string $uploadedByEmail = null,
        ?Tenant $tenant = null,
    ): StoredFile {
        $tenant ??= $this->currentTenant();
        $folder = $this->normalizeFolder($folder);
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $this->assertMimeAllowed($mime);
        $this->assertSizeAllowed($file);

        $checksum = hash_file('sha256', $file->getRealPath());
        if (! is_string($checksum)) {
            throw new StorageException('No se pudo verificar la integridad del archivo.');
        }

        $diskName = (string) config('storage.disk');
        $disk = Storage::disk($diskName);
        $existing = StoredFile::withTrashed()
            ->where('tenant_id', (string) $tenant->id)
            ->where('checksum', $checksum)
            ->first();

        if ($existing !== null && ! $existing->trashed() && $disk->exists($existing->path)) {
            return $existing;
        }

        $filename = Str::uuid().'.'.$this->extensionFor($mime);
        $directory = (string) $tenant->id.'/'.$folder;
        $writtenPath = null;
        $adopted = false;

        try {
            $result = $disk->putFileAs($directory, $file, $filename);
            if (! is_string($result) || $result === '') {
                throw new StorageException('No se pudo almacenar el archivo. Intente nuevamente.');
            }
            $writtenPath = trim($result, '/');

            $this->scanner->scan($diskName, $writtenPath);

            try {
                $stored = DB::connection(config('tenancy.database.central_connection'))
                    ->transaction(function () use (
                        $tenant, $checksum, $diskName, $writtenPath, $mime, $file,
                        $uploadedByEmail, $disk,
                    ): StoredFile {
                        // Serializa cuota y deduplicacion por colegio. En tests que
                        // pasan un Tenant no persistido, la consulta simplemente no
                        // encuentra fila; en produccion todo tenant si esta persistido.
                        Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();

                        $winner = StoredFile::withTrashed()
                            ->where('tenant_id', (string) $tenant->id)
                            ->where('checksum', $checksum)
                            ->lockForUpdate()
                            ->first();

                        if ($winner !== null) {
                            if ($disk->exists($winner->path)) {
                                if ($winner->trashed()) {
                                    $winner->restore();
                                }

                                return $winner->fresh();
                            }

                            // La metadata sobrevivio pero los bytes no: repara la
                            // misma fila para no chocar con el indice unico.
                            $winner->forceFill([
                                'disk' => $diskName,
                                'path' => $writtenPath,
                                'mime' => $mime,
                                'size' => $file->getSize(),
                                'original_name' => $file->getClientOriginalName(),
                                'uploaded_by_email' => $uploadedByEmail,
                                'deleted_at' => null,
                            ])->save();

                            return $winner->fresh();
                        }

                        $this->assertQuota($tenant, (int) $file->getSize());

                        $created = StoredFile::create([
                            'tenant_id' => (string) $tenant->id,
                            'disk' => $diskName,
                            'path' => $writtenPath,
                            'mime' => $mime,
                            'size' => $file->getSize(),
                            'checksum' => $checksum,
                            'original_name' => $file->getClientOriginalName(),
                            'uploaded_by_email' => $uploadedByEmail,
                        ]);

                        return $created;
                    }, 3);

                // Solo se conserva el objeto que quedo referenciado tras el
                // COMMIT. Si una carrera eligio otra ruta o el commit falla, el
                // finally elimina el objeto temporal y evita bytes huerfanos.
                $adopted = hash_equals($writtenPath, (string) $stored->path);

                return $stored;
            } catch (QueryException $e) {
                // Red de seguridad para una carrera en una BD sin lock efectivo:
                // el indice (tenant_id, checksum) decide el ganador.
                if (! $this->isUniqueConstraintViolation($e)) {
                    throw $e;
                }

                $winner = StoredFile::withTrashed()
                    ->where('tenant_id', (string) $tenant->id)
                    ->where('checksum', $checksum)
                    ->first();

                if ($winner === null || ! $disk->exists($winner->path)) {
                    throw $e;
                }

                if ($winner->trashed()) {
                    $winner->restore();
                }

                return $winner->fresh();
            }
        } catch (StorageException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new StorageException('No se pudo almacenar el archivo. Intente nuevamente.');
        } finally {
            if ($writtenPath !== null && ! $adopted) {
                $disk->delete($writtenPath);
            }
        }
    }

    public function delete(StoredFile $file, Tenant $tenant): void
    {
        if (! hash_equals((string) $tenant->id, (string) $file->tenant_id)) {
            throw new StorageException('El archivo no pertenece al colegio activo.');
        }

        if (! $file->trashed()) {
            $file->delete();
        }
    }

    public function signedUrl(StoredFile $file, ?int $minutes = null): string
    {
        $ttl = $minutes ?? (int) config('storage.signed_url_minutes', 15);

        return URL::temporarySignedRoute(
            'storage.file',
            now()->addMinutes($ttl),
            ['file' => $file->id, 'tenant' => $file->tenant_id],
        );
    }

    /** null significa cuota ilimitada. */
    public function quotaBytes(Tenant $tenant): ?int
    {
        $plan = Plan::query()->where('key', $tenant->plan)->first(['storage_gb']);
        if ($plan !== null) {
            return $plan->storage_gb === null
                ? null
                : $plan->storage_gb * 1024 * 1024 * 1024;
        }

        $fallbackGb = config("storage.quotas_gb.{$tenant->plan}")
            ?? config('storage.quotas_gb.esencial');

        return $fallbackGb === null ? null : (int) $fallbackGb * 1024 * 1024 * 1024;
    }

    private function currentTenant(): Tenant
    {
        $tenant = function_exists('tenant') ? tenant() : null;
        if (! $tenant instanceof Tenant) {
            throw new StorageException('No hay un colegio activo para almacenar archivos.');
        }

        return $tenant;
    }

    private function normalizeFolder(string $folder): string
    {
        $folder = trim($folder, '/');
        if ($folder === ''
            || strlen($folder) > 120
            || preg_match('#^[a-z0-9][a-z0-9_/-]*$#', $folder) !== 1
            || in_array('..', explode('/', $folder), true)) {
            throw new StorageException('La carpeta de destino no es valida.');
        }

        return $folder;
    }

    private function assertMimeAllowed(string $mime): void
    {
        if (! array_key_exists($mime, (array) config('storage.mime_whitelist'))) {
            throw new StorageException("El tipo de archivo ({$mime}) no esta permitido.");
        }
    }

    private function assertSizeAllowed(UploadedFile $file): void
    {
        if ($file->getSize() > (int) config('storage.max_file_bytes')) {
            throw new StorageException('El archivo supera el tamano maximo permitido.');
        }
    }

    private function assertQuota(Tenant $tenant, int $newBytes): void
    {
        $quota = $this->quotaBytes($tenant);
        if ($quota !== null && StoredFile::usedBytesFor((string) $tenant->id) + $newBytes > $quota) {
            throw new StorageException('El colegio alcanzo la cuota de almacenamiento de su plan.');
        }
    }

    private function extensionFor(string $mime): string
    {
        return (string) (((array) config('storage.mime_whitelist'))[$mime][0] ?? 'bin');
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $driver = DB::connection(config('tenancy.database.central_connection'))->getDriverName();

        return match ($driver) {
            'pgsql' => $sqlState === '23505',
            'mysql', 'mariadb' => $sqlState === '23000' && $driverCode === 1062,
            'sqlite' => $sqlState === '23000'
                && str_contains(strtolower($exception->getMessage()), 'unique constraint failed'),
            'sqlsrv' => $sqlState === '23000' && in_array($driverCode, [2601, 2627], true),
            default => $sqlState === '23505',
        };
    }
}
