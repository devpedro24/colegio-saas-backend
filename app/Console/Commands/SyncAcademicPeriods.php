<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\PeriodoLifecycleService;
use Illuminate\Console\Command;
use Throwable;

final class SyncAcademicPeriods extends Command
{
    protected $signature = 'academico:sincronizar-periodos {--tenant= : Slug de un colegio específico}';

    protected $description = 'Abre y cierra automáticamente los períodos según sus fechas lectivas.';

    public function handle(): int
    {
        $query = Tenant::query();
        if ($slug = $this->option('tenant')) {
            $query->where('slug', $slug);
        }

        $failures = 0;
        foreach ($query->get() as $tenant) {
            try {
                $count = $tenant->run(fn () => app(PeriodoLifecycleService::class)->synchronize());
                $this->info("{$tenant->slug}: {$count} transición(es).");
            } catch (Throwable $e) {
                $failures++;
                report($e);
                $this->error("{$tenant->slug}: no se pudieron sincronizar los períodos.");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
