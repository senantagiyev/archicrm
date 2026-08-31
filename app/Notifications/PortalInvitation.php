<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalInvitation extends Notification
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
            ->subject('Archi CRM — layihə portalına dəvət')
            ->greeting('Salam, '.$notifiable->name.'!')
            ->line('Layihənizi izləmək, brifi doldurmaq və razılaşdırmalara baxmaq üçün portala dəvət olunmusunuz.')
            ->action('Portala daxil ol', $this->link)
            ->line('Link 7 gün ərzində etibarlıdır və yalnız bu e-poçt üçün nəzərdə tutulub.');
    }
}
