<?php

namespace App\Console\Commands;

use App\Jobs\SendEnrollmentNotice;
use App\Models\Ingreso\Notificacion;
use App\Models\Tenant;
use Illuminate\Console\Command;

final class RetryEnrollmentNotices extends Command
{
    protected $signature = 'ingreso:reenviar-notificaciones {--tenant= : Slug del colegio (obligatorio)}';

    protected $description = 'Reencola hasta 100 correos pendientes de matrícula; no regenera credenciales.';

    public function handle(): int
    {
        $school = Tenant::where('slug', $this->option('tenant'))->first();
        if (! $school) {
            $this->error('Indica un colegio existente con --tenant.');

            return self::FAILURE;
        }
        $count = $school->run(function () use ($school) {
            $rows = Notificacion::whereNull('enviada_en')->orderBy('id')->limit(100)->get();
            foreach ($rows as $row) {
                SendEnrollmentNotice::dispatch((string) $school->getKey(), $row->id);
            }

            return $rows->count();
        });
        $this->info("{$count} notificaciones reencoladas. Revisa el worker y los registros de entrega.");

        return self::SUCCESS;
    }
}
