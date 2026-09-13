<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Notifications\TaskDeadlineSoon;
use App\Notifications\TaskOverdue;
use App\Services\Automation\AutomationEngine;
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
            // A departed employee is soft-deleted, which does NOT clear
            // assignee_user_id — so the row still matches while the relation
            // resolves to null. Without this the whole command dies on it.
            // Deactivated accounts are skipped too: they cannot act on the task
            // and the mail carries project and client names.
            ->whereHas('assignee', fn ($query) => $query->where('is_active', true))
            ->whereHas('project')
            ->with(['assignee', 'project'])
            ->get();

        foreach ($soon as $task) {
            $task->assignee?->notify(new TaskDeadlineSoon($task, $days));
        }

        // Newly overdue (deadline was yesterday) — assignee + project manager (TZ §5.13).
        // Gated by Əlavə B rule 9 so it can be switched off from Admin → Avtomatlaşdırmalar.
        $overdue = collect();

        if (app(AutomationEngine::class)->isEnabled('rule-9')) {
            $overdue = Task::query()
                ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
                ->whereDate('deadline', today()->subDay())
                ->whereHas('project')
                ->with(['assignee', 'project.manager'])
                ->get();

            foreach ($overdue as $task) {
                $task->assignee?->notify(new TaskOverdue($task));

                if ($task->project?->manager && ! $task->project->manager->is($task->assignee)) {
                    $task->project->manager->notify(new TaskOverdue($task));
                }
            }
        }

        $this->info(sprintf('%d yaxınlaşan, %d gecikən tapşırıq üzrə bildiriş göndərildi.', $soon->count(), $overdue->count()));

        return self::SUCCESS;
    }
}
