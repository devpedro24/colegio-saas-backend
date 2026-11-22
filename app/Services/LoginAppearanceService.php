<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginAppearance;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Support\Storage\FileScanner;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class LoginAppearanceService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Server-defined scope and metadata model also support private account photos. */
    public function __construct(private readonly ?string $scopeKey = null,
        private readonly string $modelClass = LoginAppearance::class,
        private readonly string $folder = 'login') {}

    public function key(?Tenant $school): string
    {
        return $this->scopeKey ?? ($school ? 'school:'.$school->getKey() : 'platform');
    }

    public function read(?Tenant $school): ?\Illuminate\Database\Eloquent\Model
    {
        return $this->modelClass::find($this->key($school));
    }

    public function payload(?Tenant $school, string $imageEndpoint): array
    {
        $appearance = $this->read($school);

        return [
            'image_url' => $appearance?->path ? $imageEndpoint.'?v='.$appearance->version : null,
            'version' => $appearance?->version ?? 'default',
            'customized' => (bool) $appearance?->path,
        ];
    }

    public function image(Request $request, ?Tenant $school, bool $public = false): BinaryFileResponse
    {
        $appearance = $this->read($school);
        abort_unless($appearance?->path && $request->query('v') === $appearance->version, 404);
        if ($school) {
            // Only this school's explicitly published login image is public.
            abort_unless(StoredFile::whereKey($appearance->stored_file_id)
                ->where('tenant_id', $school->getKey())->where('path', $appearance->path)->exists(), 404);
        }
        $disk = Storage::disk($appearance->disk);
        abort_unless($disk->exists($appearance->path), 404);

        return response()->file($disk->path($appearance->path), [
            'Content-Type' => $appearance->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $public ? 'public, max-age=300, must-revalidate' : 'private, no-store',
        ]);
    }

    public function change(?Tenant $school, string $version, ?UploadedFile $image, ?string $actorEmail): void
    {
        if ($image) {
            $info = @getimagesize($image->getRealPath());
            if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
                || $info[0] < 200 || $info[1] < 100 || $info[0] > 6000 || $info[1] > 6000) {
                throw ValidationException::withMessages([
                    'image' => ['Usa una imagen PNG, JPG o WebP de al menos 200 × 100 y máximo 6000 × 6000 píxeles.'],
                ]);
            }
        }

        $key = $this->key($school);
        $this->modelClass::firstOrCreate(['key' => $key], ['version' => 'default']);
        $newFile = null;
        $newPlatformPath = null;
        $previousPlatformPath = null;
        try {
            DB::connection(config('tenancy.database.central_connection'))->transaction(function () use (
                $key, $school, $version, $image, $actorEmail, &$newFile, &$newPlatformPath, &$previousPlatformPath
            ): void {
                $appearance = $this->modelClass::whereKey($key)->lockForUpdate()->firstOrFail();
                abort_unless(hash_equals($appearance->version, $version), 409,
                    'La imagen cambió en otra sesión. Revisa la vista previa actualizada e intenta nuevamente.');
                $previousFile = $appearance->stored_file_id;
                $previousPlatformPath = $school ? null : $appearance->path;
                $values = ['disk' => null, 'path' => null, 'mime' => null, 'stored_file_id' => null];
                if ($image && $school) {
                    $stored = app(StorageService::class)->store($image, $this->folder, $actorEmail, $school, self::MAX_BYTES);
                    if ($stored->wasRecentlyCreated) $newFile = $stored;
                    $values = ['stored_file_id' => $stored->id, 'disk' => $stored->disk,
                        'path' => $stored->path, 'mime' => $stored->mime];
                } elseif ($image) {
                    $mime = $image->getMimeType();
                    $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime];
                    $newPlatformPath = $this->folder.'/platform/'.bin2hex(random_bytes(16)).'.'.$extension;
                    if (! Storage::disk('local')->put($newPlatformPath, file_get_contents($image->getRealPath()))) {
                        throw new StorageException('No se pudo guardar la imagen.');
                    }
                    app(FileScanner::class)->scan('local', $newPlatformPath);
                    $values = ['stored_file_id' => null, 'disk' => 'local',
                        'path' => $newPlatformPath, 'mime' => $mime];
                }
                $appearance->fill([...$values, 'version' => bin2hex(random_bytes(16))])->save();
                if ($previousFile && $previousFile !== $appearance->stored_file_id) {
                    StoredFile::whereKey($previousFile)->where('tenant_id', $school?->getKey())->delete();
                }
            });
        } catch (Throwable $error) {
            if ($newFile) Storage::disk($newFile->disk)->delete($newFile->path);
            if ($newPlatformPath) Storage::disk('local')->delete($newPlatformPath);
            throw $error;
        }
        if ($previousPlatformPath) Storage::disk('local')->delete($previousPlatformPath);
    }
}
