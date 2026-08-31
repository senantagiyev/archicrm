<?php

namespace App\Notifications;

use App\Filament\Resources\ProjectResource;
use App\Models\Brief;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BriefCompleted extends Notification
{
    use Queueable;

    public function __construct(public Brief $brief, public Document $document) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Brif tamamlandı — '.$this->brief->project->name)
            ->line('Sifarişçi brifi tam doldurdu. PDF ixracı layihənin sənədlərinə əlavə edildi.')
            ->action('Layihəyə bax', ProjectResource::getUrl('edit', ['record' => $this->brief->project_id]));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Brif tamamlandı',
            'body' => $this->brief->project->name.' — PDF sənədlərə əlavə edildi',
            'project_id' => $this->brief->project_id,
            'document_id' => $this->document->id,
        ];
    }
}
