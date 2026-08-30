<?php

namespace App\Observers;

use App\Models\Stage;
use App\Services\Projects\ReadinessService;

class StageObserver
{
    public function __construct(private readonly ReadinessService $readiness) {}

    public function saved(Stage $stage): void
    {
        // saveQuietly() in the service prevents recursion here.
        $stage->loadMissing('project');
        $this->readiness->recalculateProject($stage->project);
    }

    public function deleted(Stage $stage): void
    {
        $stage->loadMissing('project');
        $this->readiness->recalculateProject($stage->project);
    }
}
