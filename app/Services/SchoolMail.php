<?php

namespace App\Services;

use App\Models\CorreoConfiguracion;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/** Fresh transport per send: never cache another school's credentials in a worker. */
class SchoolMail
{
    public function settings(): ?CorreoConfiguracion
    {
        if (! tenancy()->initialized || ! Schema::hasTable('correo_configuracion')) {
            return null;
        }

        return CorreoConfiguracion::whereNull('desconectado_en')->whereNotNull('app_password')->find('gmail');
    }

    public function ready(): bool
    {
        return $this->settings() !== null || ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function mailer(?CorreoConfiguracion $settings = null)
    {
        $settings ??= $this->settings();
        if (! $settings) {
            return Mail::mailer();
        }
        $mailer = Mail::build(['transport' => 'smtp', 'scheme' => 'smtps', 'host' => 'smtp.gmail.com',
            'port' => 465, 'username' => $settings->email, 'password' => $settings->app_password, 'timeout' => 10]);
        $mailer->alwaysFrom($settings->email, $settings->nombre);

        return $mailer;
    }

    public function sendRaw(string $recipient, string $subject, string $body): void
    {
        try {
            $settings = $this->settings();
            if (! $settings) {
                if (app()->environment('production') && ! $this->ready()) {
                    throw new \RuntimeException;
                }
                Mail::raw($body, fn ($mail) => $mail->to($recipient)->subject($subject));
            } else {
                $sent = $this->mailer($settings)->raw($body, fn ($mail) => $mail->to($recipient)->subject($subject));
                if (! $sent) {
                    throw new \RuntimeException;
                }
            }
        } catch (\Throwable) {
            // Never surface the SMTP dialogue or chain its possibly sensitive exception.
            throw new \RuntimeException(__('No se pudo enviar el correo. Revisa la configuración institucional y vuelve a probar.'));
        }
    }

    public function test(CorreoConfiguracion $candidate): void
    {
        try {
            $sent = $this->mailer($candidate)->raw(__('Prueba de correo institucional. Si recibiste este mensaje, revisa en la plataforma que se haya guardado la conexión. No respondas con contraseñas.'),
                fn ($mail) => $mail->to($candidate->email)->subject(__('Prueba de conexión del colegio')));
            if (! $sent) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new \RuntimeException(__('Google no aceptó la prueba. Verifica el correo, la contraseña de aplicación, la verificación en dos pasos y que el servidor permita SMTP seguro.'));
        }
    }

    public function sendEvent(#[\SensitiveParameter] string $recipient, array $event, string $locale): void
    {
        try {
            if (! $this->readyForEvents()) throw new \RuntimeException;
            $subject = ($locale === 'en' ? 'New school event: ' : 'Nuevo evento del colegio: ').$event['title'];
            $mailer = $this->mailer();
            $transport = $mailer->getSymfonyTransport();
            if ($transport instanceof \Symfony\Component\Mailer\Transport\Smtp\SmtpTransport
                && $transport->getStream() instanceof \Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream) {
                $transport->getStream()->setTimeout(10);
            }
            $sent = $mailer->send(['html' => 'mail.event', 'text' => 'mail.event-text'],
                ['event' => $event, 'english' => $locale === 'en'], fn ($mail) => $mail->to($recipient)->subject($subject));
            if (! $sent) throw new \RuntimeException;
        } catch (\Throwable) {
            throw new \RuntimeException('event_mail_transport_failed');
        }
    }

    public function readyForEvents(): bool
    {
        if ($this->settings()) return true;
        // A log/array (including a failover to log) is not SMTP acceptance.
        // Use a direct delivery transport or the school's configured SMTP.
        return in_array(config('mail.mailers.'.config('mail.default').'.transport'),
            ['smtp', 'sendmail', 'ses', 'postmark', 'resend'], true);
    }
}
