<?php

namespace App\Observers;

use App\Enums\TaskStatus;
use App\Models\Task;
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
    }

    public function deleted(Task $task): void
    {
        $task->loadMissing('stage.project');

        if ($task->stage) {
            $this->readiness->recalculateStage($task->stage);
        }
    }
}
