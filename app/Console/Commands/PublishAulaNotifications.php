<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\AulaNotifications;
use Illuminate\Console\Command;
use Throwable;

final class PublishAulaNotifications extends Command
{
    protected $signature = 'aula:publicar-novedades {--tenant= : Slug de un colegio específico}';

    protected $description = 'Publica avisos privados de recursos de Aula que ya están disponibles.';

    public function handle(): int
    {
        $failures = 0;
        $query = Tenant::whereIn('tipo', [Tenant::TIPO_COLEGIO, Tenant::TIPO_SEDE])->where('status', Tenant::STATUS_ACTIVE);
        if ($slug = $this->option('tenant')) {
            $query->where('slug', $slug);
        }
        foreach ($query->cursor() as $tenant) {
            try {
                $tenant->run(function () use ($tenant) {
                    app(AulaNotifications::class)->reconcileDue();
                    if (\Illuminate\Support\Facades\Schema::hasColumn('user_notification_updates', 'correo_estado')
                        && \App\Jobs\SendAulaMailBatch::hasDue()) {
                        \App\Jobs\SendAulaMailBatch::dispatch((string) $tenant->getKey());
                    }
                });
            } catch (Throwable $error) {
                report($error);
                $this->error("{$tenant->slug}: no se pudieron publicar los avisos.");
                $failures++;
            }
        }

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
