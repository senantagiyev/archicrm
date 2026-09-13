<?php

namespace App\Notifications;

use App\Enums\ApprovalStatus;
use App\Filament\Resources\ProjectResource;
use App\Models\Approval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the requesting staff member when the customer decides. */
class ApprovalDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Approval $approval) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verdict = $this->approval->status === ApprovalStatus::Approved ? 'razÄ±laÅŸdÄ±' : 'rÉ™dd etdi';

        return (new MailMessage)
            ->subject('RazÄ±laÅŸdÄ±rma qÉ™rarÄ± â€” '.$this->approval->project?->name)
            ->line("SifariÅŸÃ§i {$verdict}: ".$this->approval->subjectLabel())
            ->line($this->approval->comment ? 'ÅžÉ™rh: '.$this->approval->comment : '')
            ->action('LayihÉ™yÉ™ bax', ProjectResource::getUrl('edit', ['record' => $this->approval->project_id]));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->approval->status === ApprovalStatus::Approved
                ? 'Pozisiya razÄ±laÅŸdÄ±rÄ±ldÄ±'
                : 'Pozisiya rÉ™dd edildi',
            'body' => $this->approval->subjectLabel().($this->approval->comment ? ' â€” '.$this->approval->comment : ''),
            'approval_id' => $this->approval->id,
            'project_id' => $this->approval->project_id,
        ];
    }
}
