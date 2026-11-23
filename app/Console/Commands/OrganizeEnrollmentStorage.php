<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Ingreso\Solicitud;
use App\Models\Tenant;
use App\Services\Ingreso\EnrollmentStorage;
use App\Support\Storage\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class OrganizeEnrollmentStorage extends Command
{
    protected $signature = 'ingreso:organizar-archivos {--tenant=} {--apply} {--legacy}';

    protected $description = 'Organiza expedientes conservando metadatos y verifica cada archivo antes de retirar su ruta anterior.';

    public function handle(EnrollmentStorage $storage): int
    {
        $count = 0;
        foreach (Tenant::when($this->option('tenant'), fn ($query) => $query->where('slug', $this->option('tenant')))->cursor() as $school) {
            $school->run(function () use ($storage, &$count): void {
                if (! Schema::hasTable('ingreso_solicitudes')) {
                    return;
                }
                Solicitud::whereHas('documentos')->select('id')->chunkById(100, function ($applications) use ($storage, &$count): void {
                    foreach ($applications as $application) {
                        if ($this->option('apply')) {
                            $storage->organize($application->id);
                        }
                        $count++;
                    }
                });
            });
        }
        if ($this->option('legacy')) {
            $this->legacy();
        }
        $this->info(($this->option('apply') ? 'Expedientes organizados: ' : 'Expedientes por organizar: ').$count);

        return self::SUCCESS;
    }

    private function legacy(): void
    {
        $root = realpath(base_path('storage'));
        foreach (glob($root.DIRECTORY_SEPARATOR.'tenant*', GLOB_ONLYDIR) as $source) {
            if (is_link($source) || dirname(realpath($source)) !== $root || ! preg_match('/^tenant([a-zA-Z0-9-]+)$/D', basename($source), $match)) {
                continue;
            }
            $school = Tenant::find($match[1]);
            $files = File::allFiles($source);
            if (! $school) {
                foreach ($files as $file) {
                    if (! str_starts_with(str_replace('\\', '/', $file->getRelativePathname()), 'framework/testing/')) {
                        throw new RuntimeException('Hay archivos de un colegio desconocido. Se conservó la carpeta.');
                    }
                }
            }
            if (! $this->option('apply')) {
                continue;
            }
            foreach ($files as $file) {
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                if (str_starts_with($relative, 'framework/testing/')) {
                    File::delete($file->getPathname());

                    continue;
                }
                $subpath = str_starts_with($relative, 'app/public/') ? 'public/'.substr($relative, 11)
                    : (str_starts_with($relative, 'app/') ? 'local/'.substr($relative, 4) : $relative);
                $target = $root.'/app/tenants/'.StorageService::tenantFolder($school).'/sistema/'.$subpath;
                File::ensureDirectoryExists(dirname($target));
                if (! File::exists($target) && ! File::copy($file->getPathname(), $target)) {
                    throw new RuntimeException('No se pudo reubicar el archivo anterior.');
                }
                if (hash_file('sha256', $target) !== hash_file('sha256', $file->getPathname())) {
                    throw new RuntimeException('El archivo reubicado no coincide.');
                }
                File::delete($file->getPathname());
            }
            if (File::allFiles($source)) {
                throw new RuntimeException('La carpeta conserva archivos pendientes.');
            }
            File::deleteDirectory($source);
            $this->line('Carpeta anterior retirada: '.basename($source));
        }
    }
}
