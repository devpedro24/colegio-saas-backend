<?php

namespace App\Jobs;

use App\Models\Ingreso\Notificacion;
use App\Models\Tenant;
use App\Services\SchoolMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

final class SendEnrollmentNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public string $school, public int $notice) {}

    public function handle(): void
    {
        $deliver = function () {
            Cache::lock('ingreso-mail:'.$this->school.':'.$this->notice, 90)->block(5, function () {
                $notice = Notificacion::findOrFail($this->notice);
                if ($notice->enviada_en) {
                    return;
                }
                app(SchoolMail::class)->sendRaw($notice->email, $notice->asunto, $notice->contenido);
                // Secrets are encrypted at rest and discarded after delivery.
                $notice->update(['enviada_en' => now(), 'contenido' => null]);
            });
        };
        if ((string) tenant()?->getTenantKey() === $this->school) {
            $deliver();
        } else {
            Tenant::findOrFail($this->school)->run($deliver);
        }
    }
}
