<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Academico\Aula;
use App\Models\Academico\AulaAdjunto;
use App\Models\Academico\AulaEntrega;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\DatosInstitucionales;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Storage\AulaStoragePath;
use App\Support\Storage\ReadableStorageName;
use App\Support\Storage\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** One-time, idempotent layout migration for existing classroom attachments. */
final class OrganizeAulaStoredFiles extends Command
{
    protected $signature = 'storage:organize-aula {--tenant= : ID del colegio} {--apply : Copiar, verificar y actualizar rutas}';
    protected $description = 'Organiza adjuntos de aula con colegio, grado, grupo, periodo y nombre original';

    public function handle(StorageService $service): int
    {
        $query = Tenant::query();
        if ($this->option('tenant')) $query->whereKey((string) $this->option('tenant'));
        $total = 0;
        $errors = 0;
        foreach ($query->cursor() as $tenant) {
            $tenant->run(function () use ($tenant, $service, &$total, &$errors): void {
                $this->organizeLogo($tenant, $total, $errors);
                foreach (StoredFile::where('tenant_id', $tenant->id)->orderBy('id')->cursor() as $stored) {
                    $attachments = AulaAdjunto::where('archivo_token', $stored->public_token)->get();
                    if ($attachments->count() > 1) {
                        $this->warn('Varios vínculos comparten el archivo '.$stored->id.'; requiere separación antes de moverlo.');
                        $errors++;
                        continue;
                    }
                    $attachment = $attachments->first();
                    if ($attachment?->recurso_id) {
                        $resource = AulaRecurso::find($attachment->recurso_id);
                        if (! $resource) continue;
                        $folder = AulaStoragePath::resource($resource);
                    } elseif ($attachment?->entrega_id) {
                        $user = User::find($attachment->autor_id);
                        $submission = AulaEntrega::find($attachment->entrega_id);
                        if (! $user || ! $submission) continue;
                        $folder = AulaStoragePath::submission($submission, $user);
                    } else {
                        $cover = Aula::where('portada_token', $stored->public_token)->first();
                        if ($cover) $folder = AulaStoragePath::classroom($cover);
                        elseif (str_contains($stored->path, '/aula/')
                            || str_contains($stored->path, '/Archivos_sin_vinculo/')) {
                            // Keep unlinked legacy material findable without guessing an aula.
                            $folder = 'Archivos_sin_vinculo';
                        } else continue;
                    }
                    $rootSegment = explode('/', $stored->path, 2)[0];
                    if ($stored->disk !== 'tenant' || ($rootSegment !== (string) $tenant->id
                        && ! str_ends_with($rootSegment, '_'.$tenant->id))) {
                        $this->warn('Fuera de disco o colegio: archivo '.$stored->id);
                        $errors++;
                        continue;
                    }
                    $disk = Storage::disk('tenant');
                    if (! $disk->exists($stored->path)) {
                        $this->warn('No existe el original: archivo '.$stored->id);
                        $errors++;
                        continue;
                    }
                    $base = StorageService::tenantFolder($tenant).'/'.$folder;
                    $name = $service->filenameFor($stored->original_name, $stored->mime, $base);
                    $stem = pathinfo($name, PATHINFO_FILENAME);
                    $extension = pathinfo($name, PATHINFO_EXTENSION);
                    $target = $base.'/'.$name;
                    if ($target === $stored->path) continue;
                    for ($n = 2; $disk->exists($target); $n++) $target = $base.'/'.$stem.'-'.$n.'.'.$extension;
                    $legacy = $stored->path;
                    $this->line($legacy.' -> '.$target);
                    $total++;
                    if (! $this->option('apply')) continue;

                    if (! $disk->copy($legacy, $target)
                        || hash_file('sha256', $disk->path($target)) !== $stored->checksum) {
                        $disk->delete($target);
                        $this->error('Falló la copia o la verificación: archivo '.$stored->id);
                        $errors++;
                        continue;
                    }
                    try {
                        $stored->update(['path' => $target]);
                    } catch (\Throwable $error) {
                        $disk->delete($target);
                        throw $error;
                    }
                    // Keep a recoverable original outside the live tenant tree.
                    $backup = base_path('storage/app/aula-storage-migration-backups/'.$legacy);
                    if (! File::exists($backup)) {
                        File::ensureDirectoryExists(dirname($backup));
                        if (! File::move($disk->path($legacy), $backup)) {
                            $this->warn('La copia antigua permanece en su lugar: archivo '.$stored->id);
                        } else {
                            $this->pruneEmptyParents($disk->path($legacy), $disk->path(''));
                        }
                    } else {
                        $this->warn('Ya existe respaldo; la copia antigua permanece: archivo '.$stored->id);
                    }
                }
                $this->organizeUntrackedAulaFiles($tenant, $total, $errors);
                if ($this->option('apply')) $this->pruneLegacyTenantDirectories($tenant);
            });
        }
        $this->info(($this->option('apply') ? 'Migrados' : 'Por migrar').": {$total}; incidencias: {$errors}.");
        return $errors ? self::FAILURE : self::SUCCESS;
    }

    private function organizeLogo(Tenant $tenant, int &$total, int &$errors): void
    {
        $disk = Storage::disk('tenant');
        $legacy = $tenant->id.'/branding/logo.png';
        if (! $disk->exists($legacy)) return;
        $target = StorageService::tenantFolder($tenant).'/branding/logo.png';
        if ($disk->exists($target)
            && hash_file('sha256', $disk->path($target)) !== hash_file('sha256', $disk->path($legacy))) {
            $this->warn('El logo nuevo difiere del antiguo; se conserva el antiguo: '.$tenant->id);
            $errors++;
            return;
        }
        $this->line($legacy.' -> '.$target);
        $total++;
        if (! $this->option('apply')) return;
        if (! $disk->exists($target) && (! $disk->copy($legacy, $target)
            || hash_file('sha256', $disk->path($target)) !== hash_file('sha256', $disk->path($legacy)))) {
            $disk->delete($target);
            $this->warn('No se pudo verificar el logo del colegio: '.$tenant->id);
            $errors++;
            return;
        }
        DatosInstitucionales::where('logo_principal', $legacy)->update(['logo_principal' => $target]);
        $backup = base_path('storage/app/aula-storage-migration-backups/'.$legacy);
        if (! File::exists($backup)) {
            File::ensureDirectoryExists(dirname($backup));
            if (File::move($disk->path($legacy), $backup)) {
                $this->pruneEmptyParents($disk->path($legacy), $disk->path(''));
            } else {
                $this->warn('No se pudo mover el logo antiguo al respaldo: '.$tenant->id);
                $errors++;
            }
        } else {
            $this->warn('Ya existe un respaldo del logo; se conserva la copia antigua: '.$tenant->id);
            $errors++;
        }
    }

    private function organizeUntrackedAulaFiles(Tenant $tenant, int &$total, int &$errors): void
    {
        $disk = Storage::disk('tenant');
        $base = StorageService::tenantFolder($tenant).'/Archivos_sin_vinculo';
        $registered = StoredFile::withTrashed()->where('tenant_id', $tenant->id)->pluck('path')->flip();
        foreach ($disk->allFiles($tenant->id) as $legacy) {
            if ($registered->has($legacy)) continue;
            if (! str_contains($legacy, '/aula/') || ! str_contains($legacy, '/materiales/')) continue;
            $extension = strtolower(pathinfo($legacy, PATHINFO_EXTENSION));
            if (! in_array($extension, ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'webp', 'mp3', 'mp4'], true)) continue;
            $checksum = hash_file('sha256', $disk->path($legacy));
            $stem = pathinfo($legacy, PATHINFO_FILENAME);
            if (preg_match('/--([a-f0-9]{12})\z/iD', $stem, $match)
                && strtolower($match[1]) === substr($checksum, 0, 12)) {
                $stem = substr($stem, 0, -14);
            }
            $name = ReadableStorageName::segment($stem, 'Archivo', 150).'.'.$extension;
            $target = $base.'/'.$name;
            for ($n = 2; $disk->exists($target); $n++) {
                $target = $base.'/'.pathinfo($name, PATHINFO_FILENAME).'_'.$n.'.'.$extension;
            }
            $this->line($legacy.' -> '.$target.' (sin registro; no se adjunta a un recurso)');
            $total++;
            if (! $this->option('apply')) continue;
            if (! $disk->copy($legacy, $target) || hash_file('sha256', $disk->path($target)) !== $checksum) {
                $disk->delete($target);
                $this->warn('No se pudo verificar el archivo antiguo: '.$legacy);
                $errors++;
                continue;
            }
            $backup = base_path('storage/app/aula-storage-migration-backups/'.$legacy);
            if (File::exists($backup)) {
                $disk->delete($target);
                $this->warn('Ya existe respaldo; se conserva el archivo antiguo: '.$legacy);
                $errors++;
                continue;
            }
            File::ensureDirectoryExists(dirname($backup));
            if (! File::move($disk->path($legacy), $backup)) {
                $disk->delete($target);
                $this->warn('No se pudo respaldar el archivo antiguo: '.$legacy);
                $errors++;
                continue;
            }
            $this->pruneEmptyParents($disk->path($legacy), $disk->path(''));
        }
    }

    private function pruneLegacyTenantDirectories(Tenant $tenant): void
    {
        $diskRoot = realpath(Storage::disk('tenant')->path(''));
        $oldRoot = realpath(Storage::disk('tenant')->path($tenant->id));
        if ($diskRoot === false || $oldRoot === false
            || ! str_starts_with($oldRoot, $diskRoot.DIRECTORY_SEPARATOR)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($oldRoot,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if (! $item->isDir() || $item->isLink()) continue;
            $path = realpath($item->getPathname());
            if ($path !== false && str_starts_with($path, $oldRoot.DIRECTORY_SEPARATOR)
                && scandir($path) === ['.', '..']) @rmdir($path);
        }
        if (scandir($oldRoot) === ['.', '..']) @rmdir($oldRoot);
    }

    private function pruneEmptyParents(string $oldFile, string $storageRoot): void
    {
        $root = realpath($storageRoot);
        $parent = realpath(dirname($oldFile));
        if ($root === false) return;
        while ($parent !== false && str_starts_with($parent, $root.DIRECTORY_SEPARATOR)) {
            if (scandir($parent) !== ['.', '..'] || ! @rmdir($parent)) break;
            $parent = realpath(dirname($parent));
        }
    }

}
