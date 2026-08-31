<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalLoginLink extends Notification
{
    use Queueable;

    public function __construct(public string $link) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Archi CRM — giriş linki')
            ->line('Portala daxil olmaq üçün linkə klikləyin. Link 30 dəqiqə etibarlıdır.')
            ->action('Daxil ol', $this->link);
    }
}
