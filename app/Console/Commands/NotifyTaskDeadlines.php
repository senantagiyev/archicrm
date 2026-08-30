<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Notifications\TaskDeadlineSoon;
use App\Notifications\TaskOverdue;
use Illuminate\Console\Command;

class NotifyTaskDeadlines extends Command
{
    protected $signature = 'tasks:notify-deadlines';

    protected $description = 'Son tarixi yaxınlaşan tapşırıqların icraçılarına, gecikənlərdə isə icraçı + menecerə bildiriş göndərir';

    public function handle(): int
    {
        $days = (int) setting('notifications.deadline_days', 3);

        $soon = Task::query()
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->whereDate('deadline', today()->addDays($days))
            ->whereNotNull('assignee_user_id')
            ->with(['assignee', 'project'])
            ->get();

        foreach ($soon as $task) {
            $task->assignee->notify(new TaskDeadlineSoon($task, $days));
        }

        // Newly overdue (deadline was yesterday) — assignee + project manager (TZ §5.13).
        $overdue = Task::query()
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->whereDate('deadline', today()->subDay())
            ->with(['assignee', 'project.manager'])
            ->get();

        foreach ($overdue as $task) {
            $task->assignee?->notify(new TaskOverdue($task));

            if ($task->project->manager && ! $task->project->manager->is($task->assignee)) {
                $task->project->manager->notify(new TaskOverdue($task));
            }
        }

        $this->info(sprintf('%d yaxınlaşan, %d gecikən tapşırıq üzrə bildiriş göndərildi.', $soon->count(), $overdue->count()));

        return self::SUCCESS;
    }
}
