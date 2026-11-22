<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordResetCode extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $code) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Código para recuperar tu contraseña de GirCampus')
            ->greeting('Recupera tu contraseña')
            ->line('Tu código de recuperación es: '.$this->code)
            ->line('Es válido durante 10 minutos y solo se puede usar una vez. Escríbelo en la pantalla donde lo solicitaste.')
            ->line('Si no solicitaste este cambio, ignora este correo. No compartas este código.');
    }
}
