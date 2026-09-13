<?php

namespace App\Observers;

use App\Enums\TaskStatus;
use App\Models\Stage;
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
        $this->recalculateAffectedStages($task);

        // TZ §5.13: notify the assignee when a task is assigned to them.
        if ($task->wasChanged('assignee_user_id') || ($task->wasRecentlyCreated && $task->assignee_user_id)) {
            $task->loadMissing('assignee', 'project');

            // Deactivated accounts are skipped: the mail carries project and
            // client names and the person can no longer act on it anyway.
            if ($task->assignee?->is_active && $task->assignee_user_id !== auth()->id()) {
                $task->assignee->notify(new TaskAssigned($task));
            }
        }
    }

    public function deleted(Task $task): void
    {
        $this->recalculateAffectedStages($task);
    }

    /**
     * Moving a task changes the readiness of BOTH stages, so both are refreshed.
     * Stages are resolved by id rather than through `$task->stage`: on an
     * already-loaded relation that accessor still returns the stage the task came
     * FROM, which used to leave the destination stage stale forever.
     */
    private function recalculateAffectedStages(Task $task): void
    {
        $stageIds = array_unique(array_filter([
            $task->stage_id,
            $task->getOriginal('stage_id'),
        ]));

        foreach (Stage::query()->whereIn('id', $stageIds)->get() as $stage) {
            $this->readiness->recalculateStage($stage);
        }
    }
}
