<?php

namespace App\Notifications;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

class SchoolMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification)
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (\Throwable) {
            throw new \RuntimeException('No se pudo enviar la notificación. Revisa el correo institucional.');
        }
    }
}
