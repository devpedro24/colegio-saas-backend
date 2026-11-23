<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\AnoLectivo;
use App\Models\Ingreso\Solicitud;
use App\Models\StoredFile;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\ReadableStorageName;
use App\Support\Storage\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class EnrollmentStorage
{
    public function uploadFolder(Solicitud $application, string $requirement, UploadedFile $upload): string
    {
        $existing = StoredFile::where('tenant_id', tenant()->getTenantKey())
            ->whereIn('id', $application->documentos()->pluck('archivo_id'))
            ->where('checksum', hash_file('sha256', $upload->getRealPath()))
            ->where('original_name', $upload->getClientOriginalName())->first();
        $prefix = StorageService::tenantFolder(tenant()).'/';
        if ($existing && str_starts_with($existing->path, $prefix)) {
            return substr(dirname($existing->path), strlen($prefix));
        }

        return $this->folder($application).'/'.$requirement;
    }

    public function folder(Solicitud $application): string
    {
        $campaign = $application->campana;
        $campaignName = ReadableStorageName::segment($campaign->nombre, 'Convocatoria', 24).'_'.substr(hash('sha256', (string) $campaign->id), 0, 6);
        $reference = 'MAT-'.strtoupper(substr(OpaqueUrlToken::for('ingreso-solicitud', $application->id), 0, 10)).'-'.$application->id;
        if (! $application->enviada_en) {
            return 'matriculas/Borradores/'.$campaignName.'/'.$reference;
        }
        $student = ReadableStorageName::segment(($application->datos['primer_nombre'] ?? '').' '.($application->datos['primer_apellido'] ?? ''), 'Estudiante', 20);
        $submission = $application->enviada_en->timezone(tenant()->zonaHorariaInstitucional())->format('Y-m-d_Hi').'_'.$student.'_'.$reference;
        if ($application->matricula_id) {
            $year = AnoLectivo::findOrFail($campaign->ano_lectivo_id);

            return 'anos-lectivos/'.ReadableStorageName::segment($year->nombre, 'Ano', 12).'/matriculas/Matriculados/'.$submission;
        }

        return 'matriculas/Solicitudes/'.$campaignName.'/'.$submission;
    }

    public function afterCommit(Solicitud $application): void
    {
        $id = $application->id;
        DB::afterCommit(function () use ($id): void {
            try {
                $this->organize($id);
            } catch (Throwable $error) {
                // The committed application stays usable at its old paths. The scheduled command retries.
                report($error);
            }
        });
    }

    public function organize(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $application = Solicitud::lockForUpdate()->findOrFail($id);
            $directory = StorageService::tenantFolder(tenant()).'/'.$this->folder($application);
            $ids = $application->documentos()->pluck('archivo_id')->unique();
            foreach (StoredFile::where('tenant_id', tenant()->getTenantKey())->whereIn('id', $ids)->get() as $file) {
                $disk = Storage::disk($file->disk);
                $extension = pathinfo($file->path, PATHINFO_EXTENSION);
                $name = ReadableStorageName::segment(pathinfo($file->original_name, PATHINFO_FILENAME), 'Documento', 24).'_'.$file->id.'.'.$extension;
                $target = $directory.'/'.$name;
                if ($target === $file->path) {
                    continue;
                }
                $old = $file->path;
                if (! $disk->exists($old)) {
                    throw new RuntimeException('Falta un archivo del expediente.');
                }
                if (! $disk->exists($target) && ! $disk->copy($old, $target)) {
                    throw new RuntimeException('No se pudo organizar el expediente.');
                }
                if (hash_file('sha256', $disk->path($target)) !== $file->checksum) {
                    throw new RuntimeException('La copia del documento no coincide con el original.');
                }
                // Metadata is central and commits before the old bytes are released.
                $file->update(['path' => $target]);
                if (! StoredFile::withTrashed()->where('disk', $file->disk)->where('path', $old)->exists()) {
                    $disk->delete($old);
                    $this->removeEmptyParents($file->disk, dirname($old));
                }
            }
        });
    }

    private function removeEmptyParents(string $diskName, string $directory): void
    {
        $disk = Storage::disk($diskName);
        while (str_contains($directory, '/')) {
            if ($disk->files($directory) || $disk->directories($directory)) {
                return;
            }
            $disk->deleteDirectory($directory);
            $directory = dirname($directory);
        }
    }
}
