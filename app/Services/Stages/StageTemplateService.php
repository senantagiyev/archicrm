<?php

namespace App\Services\Stages;

use App\Enums\StageStatus;
use App\Models\Project;
use App\Models\StageTemplate;
use Illuminate\Support\Carbon;

class StageTemplateService
{
    /**
     * Apply a template's items as stages appended after the project's existing
     * stages. Plan dates are laid out sequentially from $startFrom using each
     * item's default duration.
     */
    public function apply(Project $project, StageTemplate $template, ?Carbon $startFrom = null): void
    {
        $position = ($project->stages()->max('position') ?? 0) + 1;
        $cursor = $startFrom?->copy();

        foreach ($template->items as $item) {
            $planStart = $cursor?->copy();
            $planEnd = ($cursor !== null && $item->default_duration_days)
                ? $cursor->copy()->addDays($item->default_duration_days)
                : null;

            $project->stages()->create([
                'name' => $item->getTranslation('name', app()->getLocale()),
                'position' => $position++,
                'status' => StageStatus::NotStarted,
                'date_plan_start' => $planStart,
                'date_plan_end' => $planEnd,
            ]);

            if ($planEnd !== null) {
                $cursor = $planEnd->copy()->addDay();
            }
        }
    }
}
