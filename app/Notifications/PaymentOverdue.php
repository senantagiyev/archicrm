<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentOverdue extends Notification
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
            ->subject('Ödəniş gecikir — '.$this->payment->project->name)
            ->line("\"{$this->payment->title}\" ödənişinin plan tarixi keçib.")
            ->line('Məbləğ: '.number_format((float) $this->payment->amount, 2).' ₼')
            ->action('Layihəyə bax', url('/app/projects/'.$this->payment->project_id.'/edit'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Ödəniş gecikir',
            'body' => $this->payment->title.' — '.$this->payment->project->name.' ('.number_format((float) $this->payment->amount, 2).' ₼)',
            'project_id' => $this->payment->project_id,
        ];
    }
}
