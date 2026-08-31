<?php

namespace App\Notifications;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskAssigned extends Notification
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
            ->subject('Yeni tapşırıq: '.$this->task->title)
            ->line("Sizə yeni tapşırıq təyin edildi: {$this->task->title}")
            ->line('Layihə: '.$this->task->project->name)
            ->line($this->task->deadline ? 'Son tarix: '.$this->task->deadline->format('d.m.Y') : '')
            ->action('Tapşırığa bax', TaskResource::getUrl());
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Yeni tapşırıq təyin edildi',
            'body' => $this->task->title.' — '.$this->task->project->name,
            'task_id' => $this->task->id,
            'project_id' => $this->task->project_id,
        ];
    }
}
