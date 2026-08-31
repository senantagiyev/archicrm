<?php

namespace App\Notifications;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the requesting staff member when the customer decides. */
class ApprovalDecided extends Notification
{
    use Queueable;

    public function __construct(public Approval $approval) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verdict = $this->approval->status === ApprovalStatus::Approved ? 'razılaşdı' : 'rədd etdi';

        return (new MailMessage)
            ->subject('Razılaşdırma qərarı — '.$this->approval->project->name)
            ->line("Sifarişçi {$verdict}: ".$this->approval->subjectLabel())
            ->line($this->approval->comment ? 'Şərh: '.$this->approval->comment : '')
            ->action('Layihəyə bax', url('/app/projects/'.$this->approval->project_id.'/edit'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->approval->status === ApprovalStatus::Approved
                ? 'Pozisiya razılaşdırıldı'
                : 'Pozisiya rədd edildi',
            'body' => $this->approval->subjectLabel().($this->approval->comment ? ' — '.$this->approval->comment : ''),
            'approval_id' => $this->approval->id,
            'project_id' => $this->approval->project_id,
        ];
    }
}
