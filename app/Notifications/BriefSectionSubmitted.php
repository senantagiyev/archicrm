<?php

namespace App\Notifications;

use App\Filament\Resources\ProjectResource;
use App\Models\Brief;
use App\Models\BriefRoom;
use App\Models\BriefSection;
use App\Notifications\Concerns\ShowsInPanel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BriefSectionSubmitted extends Notification
{
    use Queueable;
    use ShowsInPanel;

    public function __construct(
        public Brief $brief,
        public BriefSection $section,
        public ?BriefRoom $room,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $name = $this->section->getTranslation('name', 'az').($this->room ? ' — '.$this->room->label : '');

        return $this->panelMessage(
            title: 'Brif bölməsi göndərildi',
            body: $name.' · '.$this->brief->project?->name,
            url: ProjectResource::getUrl('brief-review', ['record' => $this->brief->project_id, 'brief' => $this->brief->id]),
            icon: 'heroicon-o-clipboard-document-list',
            extra: ['project_id' => $this->brief->project_id],
            actionLabel: 'Brifə bax',
        );
    }
}
