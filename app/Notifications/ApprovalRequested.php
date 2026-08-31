<?php

namespace App\Notifications;

use App\Models\Approval;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the customer (ClientUser) when a row is submitted for approval. */
class ApprovalRequested extends Notification
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
            ->subject('Razılaşdırma tələb olunur — '.$this->approval->project->name)
            ->line('Sizin qərarınız gözlənilir: '.$this->approval->subjectLabel())
            ->line($this->approval->respond_by ? 'Cavab müddəti: '.$this->approval->respond_by->format('d.m.Y') : '')
            ->action('Bax və qərar ver', url('/portal/projects/'.$this->approval->project_id.'/approvals'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Razılaşdırma tələb olunur',
            'body' => $this->approval->subjectLabel(),
            'approval_id' => $this->approval->id,
            'project_id' => $this->approval->project_id,
        ];
    }
}
