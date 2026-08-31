<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskDeadlineSoon extends Notification
{
    use Queueable;

    public function __construct(public Task $task, public int $daysLeft) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Son tarix yaxınlaşır: '.$this->task->title)
            ->line("\"{$this->task->title}\" tapşırığının son tarixinə {$this->daysLeft} gün qalıb.")
            ->line('Layihə: '.$this->task->project->name)
            ->action('Tapşırığa bax', \App\Filament\Resources\TaskResource::getUrl());
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => "Son tarixə {$this->daysLeft} gün qalıb",
            'body' => $this->task->title.' — '.$this->task->project->name,
            'task_id' => $this->task->id,
            'project_id' => $this->task->project_id,
        ];
    }
}
