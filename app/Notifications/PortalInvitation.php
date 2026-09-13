<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalInvitation extends Notification implements ShouldQueue
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
            ->subject('Archi CRM â€” layihÉ™ portalÄ±na dÉ™vÉ™t')
            ->greeting('Salam, '.$notifiable->name.'!')
            ->line('LayihÉ™nizi izlÉ™mÉ™k, brifi doldurmaq vÉ™ razÄ±laÅŸdÄ±rmalara baxmaq Ã¼Ã§Ã¼n portala dÉ™vÉ™t olunmusunuz.')
            ->action('Portala daxil ol', $this->link)
            ->line('Link 7 gÃ¼n É™rzindÉ™ etibarlÄ±dÄ±r vÉ™ yalnÄ±z bu e-poÃ§t Ã¼Ã§Ã¼n nÉ™zÉ™rdÉ™ tutulub.');
    }
}
