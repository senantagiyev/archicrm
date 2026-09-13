<?php

namespace App\Notifications;

use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskAssigned extends Notification implements ShouldQueue
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
            ->subject('Yeni tapÅŸÄ±rÄ±q: '.$this->task->title)
            ->line("SizÉ™ yeni tapÅŸÄ±rÄ±q tÉ™yin edildi: {$this->task->title}")
            ->line('LayihÉ™: '.$this->task->project?->name)
            ->line($this->task->deadline ? 'Son tarix: '.$this->task->deadline->format('d.m.Y') : '')
            ->action('TapÅŸÄ±rÄ±ÄŸa bax', TaskResource::getUrl());
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Yeni tapÅŸÄ±rÄ±q tÉ™yin edildi',
            'body' => $this->task->title.' â€” '.$this->task->project?->name,
            'task_id' => $this->task->id,
            'project_id' => $this->task->project_id,
        ];
    }
}
