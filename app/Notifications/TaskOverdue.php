<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskOverdue extends Notification
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
            ->subject('Tapşırıq gecikdi: '.$this->task->title)
            ->line("\"{$this->task->title}\" tapşırığının son tarixi keçdi.")
            ->line('Layihə: '.$this->task->project->name)
            ->action('Tapşırığa bax', url('/app/tasks'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Tapşırıq gecikdi',
            'body' => $this->task->title.' — '.$this->task->project->name,
            'task_id' => $this->task->id,
            'project_id' => $this->task->project_id,
        ];
    }
}
