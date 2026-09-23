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

        // Bitmiş mərhələ hər iki ekranda 100%-dir. Əvvəl yalnız layihə hesabı
        // `Done`-u 100 sayırdı, mərhələ sütunu isə tapşırıq payını göstərirdi:
        // yarısı bağlanmamış, amma bitmiş elan edilmiş mərhələ bir ekranda 50%,
        // o birində 100% yazırdı.
        $readiness = $stage->status === StageStatus::Done
            ? 100
            : (($counts->total ?? 0) > 0 ? (int) round($counts->done / $counts->total * 100) : 0);

        if ($stage->readiness !== $readiness) {
            $stage->forceFill(['readiness' => $readiness])->saveQuietly();
        }

        $this->recalculateProject($stage->project);
    }

    /**
     * Accepts null on purpose: stages and tasks are not soft-deleted, so every
     * write path on a child of an ARCHIVED project arrives here with a null
     * parent. Typing this non-nullable turned an ordinary archive into a fatal
     * that also killed the nightly scanner mid-loop.
     */
    public function recalculateProject(?Project $project): void
    {
        if (! $project) {
            return;
        }

        $stages = $project->stages()->get(['id', 'readiness', 'weight', 'status']);

        if ($stages->isEmpty()) {
            $readiness = 0;
        } else {
            // Bütün çəkilər 0 olanda `max(1, …)` məxrəci 1 edirdi, surət isə
            // sıfıra vururdu — tam bitmiş layihə 0% göstərirdi. Belə halda
            // çəkilər mənasızdır, ona görə bərabər paya keçirik.
            $weight = fn (Stage $s) => $stages->sum('weight') > 0 ? (int) $s->weight : 1;
            $totalWeight = $stages->sum($weight);

            $readiness = (int) round(
                $stages->sum(fn (Stage $s) => ($s->status === StageStatus::Done ? 100 : $s->readiness) * $weight($s))
                / max(1, $totalWeight)
            );
        }

        if ((int) $project->readiness !== $readiness) {
            $project->forceFill(['readiness' => $readiness])->saveQuietly();
        }
    }
}
