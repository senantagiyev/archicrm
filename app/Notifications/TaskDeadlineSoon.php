<?php

namespace App\Notifications;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use App\Notifications\Concerns\ShowsInPanel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskDeadlineSoon extends Notification implements ShouldQueue
{
    use Queueable;
    use ShowsInPanel;

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
            ->line('Layihə: '.$this->task->project?->name)
            ->action('Tapşırığa bax', TaskResource::getUrl());
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->panelMessage(
            title: "Son tarixə {$this->daysLeft} gün qalıb",
            body: $this->task->title.' — '.$this->task->project?->name,
            url: TaskResource::getUrl('edit', ['record' => $this->task->id]),
            icon: 'heroicon-o-clock',
            color: 'warning',
            extra: ['task_id' => $this->task->id, 'project_id' => $this->task->project_id],
            actionLabel: 'Tapşırığa bax',
        );
    }
}
