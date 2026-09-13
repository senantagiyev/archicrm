<?php

namespace App\Notifications;

use App\Filament\Resources\ProjectResource;
use App\Models\Brief;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BriefCompleted extends Notification implements ShouldQueue
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
            ->subject('Brif tamamlandÄ± â€” '.$this->brief->project?->name)
            ->line('SifariÅŸÃ§i brifi tam doldurdu. PDF ixracÄ± layihÉ™nin sÉ™nÉ™dlÉ™rinÉ™ É™lavÉ™ edildi.')
            ->action('LayihÉ™yÉ™ bax', ProjectResource::getUrl('edit', ['record' => $this->brief->project_id]));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Brif tamamlandÄ±',
            'body' => $this->brief->project?->name.' â€” PDF sÉ™nÉ™dlÉ™rÉ™ É™lavÉ™ edildi',
            'project_id' => $this->brief->project_id,
            'document_id' => $this->document->id,
        ];
    }
}
