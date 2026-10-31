<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\BoletinEmision;
use App\Models\Academico\BoletinPlantilla;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Storage\StorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class BulletinArchiveSync
{
    public function forTemplate(BoletinPlantilla $template, ?User $actor): void
    {
        foreach (BoletinEmision::where('plantilla_id', $template->id)->whereNotNull('pdf_path')->orderBy('id')->cursor() as $issue) {
            $this->relocate($issue, true, $actor);
        }
    }

    public function relocate(BoletinEmision $issue, bool $apply, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($issue, $apply, $actor): bool {
            $locked = BoletinEmision::whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $old = $locked->pdf_path;
            $root = StorageService::tenantFolder(tenant()).'/boletines';
            abort_unless($old && (str_starts_with($old, $root.'/') || str_starts_with($old, tenant()->id.'/boletines/')), 409, 'Ruta fuera del colegio.');
            abort_if(in_array('..', explode('/', $old), true) || in_array('.', explode('/', $old), true) || str_contains($old, '\\'), 409, 'Ruta antigua no válida.');
            if (BulletinArchive::path($locked) === $old) {
                return false;
            }
            $disk = Storage::disk('tenant');
            $bytes = $disk->get($old);
            abort_unless(hash_equals($locked->pdf_sha256, hash('sha256', $bytes)), 409, 'El archivo original no coincide con su huella.');
            if (! $apply) {
                return true;
            }
            $backup = 'bulletin-migration-backups/'.$old;
            $private = Storage::disk('local');
            if (! $private->exists($backup)) {
                abort_unless($private->put($backup, $bytes), 500, 'No se pudo respaldar el original.');
            }
            abort_unless(hash_equals($locked->pdf_sha256, hash('sha256', $private->get($backup))), 409, 'El respaldo no coincide.');
            $target = BulletinArchive::write($locked, $bytes);
            abort_unless(hash_equals($locked->pdf_sha256, hash('sha256', $disk->get($target))), 409, 'La copia no coincide.');
            $locked->update(['pdf_path' => $target]);
            AuditLogger::tenant($actor, 'RELOCATE', 'boletin_emision', (string) $locked->id,
                ['pdf_path' => $old], ['pdf_path' => $target, 'sha256' => $locked->pdf_sha256], 'Sincronización del nombre del archivo privado.');
            // Keep old bytes until the outermost rename transaction has committed.
            DB::afterCommit(function () use ($disk, $old, $root): void {
                if (! BoletinEmision::where('pdf_path', $old)->exists()) {
                    $disk->delete($old);
                    $this->removeEmptyParents($disk->path(dirname($old)), $disk->path($root));
                }
            });

            return true;
        });
    }

    private function removeEmptyParents(string $directory, string $root): void
    {
        $root = realpath($root);
        while ($root && ($resolved = realpath($directory)) && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            // Non-recursive: a nonempty directory can never be removed here.
            if (! @rmdir($resolved)) {
                return;
            }
            $directory = dirname($resolved);
        }
    }
}
