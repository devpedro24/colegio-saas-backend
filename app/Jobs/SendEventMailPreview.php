<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\SchoolMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/** Explicit operator test: one recipient, real template, no event/student mutation. */
final class SendEventMailPreview implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public string $school, #[\SensitiveParameter] private string $recipient, public string $receipt)
    {
        $this->onConnection('event_mail');
        $this->onQueue('event-mail');
    }

    public function handle(): void
    {
        // Do not retry an ambiguous SMTP acceptance automatically in this diagnostic.
        $key = 'event-mail-preview:'.$this->receipt;
        if ((Cache::get($key, [])['status'] ?? null) === 'smtp_accepted') return;
        Cache::put($key, ['status' => 'sending', 'started_at' => now()->toIso8601String()], now()->addDay());
        try {
            if (! app()->environment('local', 'testing')) throw new \RuntimeException;
            $school = Tenant::where('status', Tenant::STATUS_ACTIVE)->findOrFail($this->school);
            $school->run(function () use ($school): void {
                $zone = $school->zonaHorariaInstitucional();
                app(SchoolMail::class)->sendEvent($this->recipient, [
                    'school' => $school->name,
                    'title' => '[PRUEBA] Presentación del calendario escolar',
                    'description' => "Este es un evento de prueba para comprobar el diseño y la entrega del correo de {$school->name}.\n\nLa invitación real incluirá aquí el propósito del evento y las indicaciones para los estudiantes.\n\nEste mensaje fue enviado únicamente al buzón de prueba autorizado. No se publicó ningún evento ni se notificó a estudiantes. El botón abre el calendario local y no una actividad real.",
                    'date' => now($zone)->toDateString(), 'time' => now($zone)->format('H:i'), 'zone' => $zone,
                    'url' => app(EnrollmentIntake::class)->baseUrl().'/comunicacion/eventos',
                ], 'es');
            });
            Cache::put($key, ['status' => 'smtp_accepted', 'completed_at' => now()->toIso8601String(),
                'recipients' => 1, 'inbox_delivery_verified' => false], now()->addDay());
        } catch (\Throwable) {
            Cache::put($key, ['status' => 'failed', 'completed_at' => now()->toIso8601String()], now()->addDay());
            throw new \RuntimeException('event_mail_preview_failed');
        }
    }
}
