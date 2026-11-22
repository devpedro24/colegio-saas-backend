<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordWasReset extends Notification
{
    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Tu contraseña de GirCampus fue restablecida')
            ->greeting('Contraseña actualizada')
            ->line('La contraseña de tu cuenta se restableció y las sesiones anteriores se cerraron.')
            ->line('Si no realizaste este cambio, contacta de inmediato al administrador de tu colegio o al soporte de GirCampus.');
    }
}
