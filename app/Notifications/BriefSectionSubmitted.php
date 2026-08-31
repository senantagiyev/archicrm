<?php

namespace App\Notifications;

use App\Models\Brief;
use App\Models\BriefRoom;
use App\Models\BriefSection;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BriefSectionSubmitted extends Notification
{
    use Queueable;

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

        return [
            'title' => 'Brif bölməsi göndərildi',
            'body' => $name.' · '.$this->brief->project->name,
            'project_id' => $this->brief->project_id,
        ];
    }
}
