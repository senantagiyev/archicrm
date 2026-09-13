<?php

namespace App\Notifications;

use App\Models\Approval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the customer (ClientUser) when a row is submitted for approval. */
class ApprovalRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Approval $approval) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('RazÄ±laÅŸdÄ±rma tÉ™lÉ™b olunur â€” '.$this->approval->project?->name)
            ->line('Sizin qÉ™rarÄ±nÄ±z gÃ¶zlÉ™nilir: '.$this->approval->subjectLabel())
            ->line($this->approval->respond_by ? 'Cavab mÃ¼ddÉ™ti: '.$this->approval->respond_by->format('d.m.Y') : '')
            ->action('Bax vÉ™ qÉ™rar ver', url('/portal/projects/'.$this->approval->project_id.'/approvals'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'RazÄ±laÅŸdÄ±rma tÉ™lÉ™b olunur',
            'body' => $this->approval->subjectLabel(),
            'approval_id' => $this->approval->id,
            'project_id' => $this->approval->project_id,
        ];
    }
}
