<?php

namespace App\Jobs;

use App\Models\Ingreso\Notificacion;
use App\Models\Tenant;
use App\Services\Ingreso\EnrollmentMailDeliveryException;
use App\Services\SchoolMail;
use App\Services\SchoolMailDeferred;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

final class SendEnrollmentNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Waiting for sender capacity is not a failed delivery attempt.
    public int $tries = 0;
    public int $maxExceptions = 5;

    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public string $school, public int $notice) {}

    public function handle(): void
    {
        $deliver = function () {
            Cache::lock('ingreso-mail:'.$this->school.':'.$this->notice, 90)->block(5, function () {
                $this->deliver(Notificacion::findOrFail($this->notice));
            });
        };
        if ((string) tenant()?->getTenantKey() === $this->school) {
            $deliver();
        } else {
            Tenant::findOrFail($this->school)->run($deliver);
        }
    }

    private function deliver(Notificacion $notice): void
    {
        if ($notice->enviada_en || ! in_array($notice->estado, ['pendiente', 'reenvio'], true)) {
            return;
        }
        $tracked = $notice->tipo !== null;
        if ($tracked && ! $notice->solicitud_id) {
            return;
        }
        $resend = $notice->estado === 'reenvio';
        $row = Notificacion::whereKey($notice->id)->whereNull('enviada_en');
        $attempt = $notice->intentos + 1;
        if ($tracked) {
            $inFlight = $resend ? 'reenviando' : 'enviando';
            if (! (clone $row)->where('estado', $notice->estado)->where('intentos', $notice->intentos)
                ->update(['estado' => $inFlight, 'intentos' => $attempt, 'intento_en' => now(), 'proximo_despacho_en' => null])) {
                return;
            }
            $row->where('estado', $inFlight)->where('intentos', $attempt);
        }

        try {
            app(SchoolMail::class)->sendEnrollment($notice->email, $notice->asunto, $notice->contenido, $notice->clave);
        } catch (SchoolMailDeferred $deferred) {
            if ($tracked) {
                $row->update(['estado' => $notice->estado, 'intentos' => $notice->intentos,
                    'proximo_despacho_en' => $resend ? now()->addSeconds($deferred->seconds + 300) : null]);
            }
            if (! $this->job || $this->job instanceof \Illuminate\Queue\Jobs\SyncJob) {
                throw new EnrollmentMailDeliveryException(__('El remitente alcanzó su límite temporal. El aviso sigue pendiente de envío.'));
            }
            $this->release($deferred->seconds);
            return;
        } catch (\Throwable $error) {
            if ($tracked) {
                if ($resend || ! $error instanceof EnrollmentMailDeliveryException || $error->uncertain) {
                    $row->update(['estado' => 'en_revision']);
                    return;
                }
                $row->update(['estado' => 'pendiente']);
            }
            throw $error;
        }
        $row->update(['estado' => 'enviado', 'enviada_en' => now(), 'contenido' => null]);
    }
}
