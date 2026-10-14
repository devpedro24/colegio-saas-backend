<?php

namespace App\Console\Commands;

use App\Jobs\SendEventMailBatch;
use App\Models\Tenant;
use App\Services\EventMailOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class DispatchEventMail extends Command
{
    protected $signature = 'eventos:enviar-correos {--tenant= : Slug del colegio}';
    protected $description = 'Encola lotes pendientes de notificaciones de eventos.';

    public function handle(): int
    {
        $failed = false;
        foreach (Tenant::where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))->cursor() as $school) {
            try {
                $school->run(function () use ($school) {
                    if (Schema::hasTable('evento_correos') && app(EventMailOutbox::class)->due()->exists()) {
                        SendEventMailBatch::dispatch((string) $school->getKey());
                    }
                });
            } catch (\Throwable) {
                $failed = true;
                $this->error("{$school->slug}: no se pudo encolar el lote; revisa base de datos y cola.");
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
