<?php

namespace App\Services\Projects;

use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Stage;

/**
 * TZ §5.10: stage readiness % = share of done tasks;
 * project readiness % = weighted average across stages (weight column).
 * Both are cached columns so lists and dashboards never aggregate live.
 */
class ReadinessService
{
    public function recalculateStage(Stage $stage): void
    {
        $counts = $stage->tasks()
            ->selectRaw('count(*) as total, sum(case when status = ? then 1 else 0 end) as done', [TaskStatus::Done->value])
            ->first();

        $readiness = ($counts->total ?? 0) > 0
            ? (int) round($counts->done / $counts->total * 100)
            : ($stage->status === StageStatus::Done ? 100 : 0);

        if ($stage->readiness !== $readiness) {
            $stage->forceFill(['readiness' => $readiness])->saveQuietly();
        }

        $this->recalculateProject($stage->project);
    }

    public function recalculateProject(Project $project): void
    {
        $stages = $project->stages()->get(['id', 'readiness', 'weight', 'status']);

        if ($stages->isEmpty()) {
            $readiness = 0;
        } else {
            $totalWeight = max(1, $stages->sum('weight'));
            $readiness = (int) round(
                $stages->sum(fn (Stage $s) => ($s->status === StageStatus::Done ? 100 : $s->readiness) * $s->weight)
                / $totalWeight
            );
        }

        if ((int) $project->readiness !== $readiness) {
            $project->forceFill(['readiness' => $readiness])->saveQuietly();
        }
    }
}
