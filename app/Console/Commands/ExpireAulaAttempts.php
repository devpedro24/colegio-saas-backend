<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AulaAttemptExpiryService;
use Illuminate\Console\Command;
use Throwable;

final class ExpireAulaAttempts extends Command
{
    protected $signature = 'aula:expirar-intentos {--tenant= : Slug de un colegio específico}';

    protected $description = 'Cierra intentos de cuestionario vencidos sin asignar cero automáticamente.';

    public function handle(): int
    {
        $query = Tenant::query()->where('tipo', Tenant::TIPO_COLEGIO);
        if ($slug = $this->option('tenant')) {
            $query->where('slug', $slug);
        }

        $failures = 0;
        foreach ($query->cursor() as $tenant) {
            try {
                $count = $tenant->run(fn () => app(AulaAttemptExpiryService::class)->expireDue());
                if ($count) {
                    $this->info("{$tenant->slug}: {$count} intento(s) vencido(s).");
                }
            } catch (Throwable $error) {
                report($error);
                $this->error("{$tenant->slug}: no se pudieron expirar los intentos.");
                $failures++;
            }
        }

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
