<?php

namespace App\Notifications;

use App\Enums\ApprovalStatus;
use App\Filament\Resources\ApprovalResource;
use App\Filament\Resources\ProjectResource;
use App\Models\Approval;
use App\Notifications\Concerns\ShowsInPanel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the requesting staff member when the customer decides. */
class ApprovalDecided extends Notification implements ShouldQueue
{
    use Queueable;
    use ShowsInPanel;

    public function __construct(public Approval $approval) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verdict = $this->approval->status === ApprovalStatus::Approved ? 'təsdiqlədi' : 'düzəliş tələb etdi';

        return (new MailMessage)
            ->subject('Razılaşdırma qərarı — '.$this->approval->project?->name)
            ->line("Sifarişçi {$verdict}: ".$this->approval->subjectLabel())
            ->line($this->approval->comment ? 'Şərh: '.$this->approval->comment : '')
            ->action('Layihəyə bax', ProjectResource::getUrl('edit', ['record' => $this->approval->project_id]));
    }

    public function toDatabase(object $notifiable): array
    {
        $approved = $this->approval->status === ApprovalStatus::Approved;

        return $this->panelMessage(
            // Mövzu material, smeta, mərhələ və s. ola bilər — «Pozisiya» yalnız
            // komplektasiyaya uyğun idi. Nə olduğu `body`-də (subjectLabel) yazılır.
            title: $approved ? 'Müştəri təsdiqlədi' : 'Müştəri düzəliş tələb etdi',
            body: $this->approval->subjectLabel().($this->approval->comment ? ' — '.$this->approval->comment : ''),
            url: ApprovalResource::getUrl(),
            icon: $approved ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle',
            color: $approved ? 'success' : 'danger',
            extra: ['approval_id' => $this->approval->id, 'project_id' => $this->approval->project_id],
        );
    }
}
