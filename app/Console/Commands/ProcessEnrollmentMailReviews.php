<?php

namespace App\Console\Commands;

use App\Jobs\SendEnrollmentNotice;
use App\Models\Ingreso\Notificacion;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class ProcessEnrollmentMailReviews extends Command
{
    protected $signature = 'ingreso:procesar-revisiones {--tenant= : Slug del colegio}';
    protected $description = 'Detecta entregas interrumpidas y encola los reenvíos autorizados de matrícula.';

    public function handle(): int
    {
        $failed = false;
        $schools = Tenant::where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug));
        foreach ($schools->cursor() as $school) {
            try {
                $school->run(function () use ($school) {
                    if (! Schema::hasColumn('ingreso_notificaciones', 'proximo_despacho_en')) {
                        return;
                    }
                    Notificacion::recoverInterrupted();
                    $due = Notificacion::where('estado', 'reenvio')->whereNull('enviada_en')->where('proximo_despacho_en', '<=', now());
                    foreach ((clone $due)->orderBy('proximo_despacho_en')->orderBy('id')->limit(100)->pluck('id') as $id) {
                        // Recover even if the dispatcher stops between the tenant write and the central queue insert.
                        if ((clone $due)->whereKey($id)->update(['proximo_despacho_en' => now()->addMinutes(5)])) {
                            SendEnrollmentNotice::dispatch((string) $school->getKey(), $id);
                        }
                    }
                });
            } catch (\Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
