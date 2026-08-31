<?php

namespace App\Observers;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Notifications\TaskAssigned;
use App\Services\Projects\ReadinessService;

class TaskObserver
{
    public function __construct(private readonly ReadinessService $readiness) {}

    public function saving(Task $task): void
    {
        if ($task->isDirty('status')) {
            $task->completed_at = $task->status === TaskStatus::Done ? now() : null;
        }
    }

    public function saved(Task $task): void
    {
        $task->loadMissing('stage.project');

        if ($task->stage) {
            $this->readiness->recalculateStage($task->stage);
        }

        // TZ §5.13: notify the assignee when a task is assigned to them.
        if ($task->wasChanged('assignee_user_id') || ($task->wasRecentlyCreated && $task->assignee_user_id)) {
            $task->loadMissing('assignee', 'project');

            if ($task->assignee && $task->assignee_user_id !== auth()->id()) {
                $task->assignee->notify(new TaskAssigned($task));
            }
        }
    }

    public function deleted(Task $task): void
    {
        $task->loadMissing('stage.project');

        if ($task->stage) {
            $this->readiness->recalculateStage($task->stage);
        }
    }
}
