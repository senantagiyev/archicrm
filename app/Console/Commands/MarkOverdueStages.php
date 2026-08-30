<?php

namespace App\Console\Commands;

use App\Enums\StageStatus;
use App\Models\Stage;
use Illuminate\Console\Command;

class MarkOverdueStages extends Command
{
    protected $signature = 'stages:mark-overdue';

    protected $description = 'Bitmə tarixi keçmiş, hazır olmayan mərhələləri avtomatik "Gecikib" statusuna keçirir (TZ §5.10)';

    public function handle(): int
    {
        $count = 0;

        Stage::query()
            ->whereNotIn('status', [StageStatus::Done->value, StageStatus::Overdue->value])
            ->whereNotNull('date_plan_end')
            ->whereDate('date_plan_end', '<', today())
            ->with('project')
            ->each(function (Stage $stage) use (&$count) {
                $stage->update(['status' => StageStatus::Overdue]);
                $count++;
            });

        $this->info("{$count} mərhələ \"Gecikib\" statusuna keçirildi.");

        return self::SUCCESS;
    }
}
