<?php

namespace App\Notifications;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Task $task) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('TapÅŸÄ±rÄ±q gecikdi: '.$this->task->title)
            ->line("\"{$this->task->title}\" tapÅŸÄ±rÄ±ÄŸÄ±nÄ±n son tarixi keÃ§di.")
            ->line('LayihÉ™: '.$this->task->project?->name)
            ->action('TapÅŸÄ±rÄ±ÄŸa bax', TaskResource::getUrl());
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'TapÅŸÄ±rÄ±q gecikdi',
            'body' => $this->task->title.' â€” '.$this->task->project?->name,
            'task_id' => $this->task->id,
            'project_id' => $this->task->project_id,
        ];
    }
}
