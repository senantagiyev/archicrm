<?php

namespace App\Notifications;

use App\Filament\Resources\ProjectResource;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ã–dÉ™niÅŸ gecikir â€” '.$this->payment->project?->name)
            ->line("\"{$this->payment->title}\" Ã¶dÉ™niÅŸinin plan tarixi keÃ§ib.")
            ->line('MÉ™blÉ™ÄŸ: '.number_format((float) $this->payment->amount, 2).' â‚¼')
            ->action('LayihÉ™yÉ™ bax', ProjectResource::getUrl('edit', ['record' => $this->payment->project_id]));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Ã–dÉ™niÅŸ gecikir',
            'body' => $this->payment->title.' â€” '.$this->payment->project?->name.' ('.number_format((float) $this->payment->amount, 2).' â‚¼)',
            'project_id' => $this->payment->project_id,
        ];
    }
}
