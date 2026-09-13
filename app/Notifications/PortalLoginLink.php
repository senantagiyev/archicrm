<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalLoginLink extends Notification implements ShouldQueue
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
            ->subject('Archi CRM â€” giriÅŸ linki')
            ->line('Portala daxil olmaq Ã¼Ã§Ã¼n linkÉ™ kliklÉ™yin. Link 30 dÉ™qiqÉ™ etibarlÄ±dÄ±r.')
            ->action('Daxil ol', $this->link);
    }
}
