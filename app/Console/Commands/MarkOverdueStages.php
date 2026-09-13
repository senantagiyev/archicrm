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
            // Stages are not soft-deleted, so an archived project's stages still
            // match; nobody wants "overdue" alerts about work that was shelved.
            ->whereHas('project')
            ->with('project')
            // chunkById, not each(): each() pages by OFFSET while the loop mutates
            // the very column the WHERE filters on, which silently skips rows.
            ->chunkById(200, function ($stages) use (&$count) {
                foreach ($stages as $stage) {
                    $stage->update(['status' => StageStatus::Overdue]);
                    $count++;
                }
            });

        $this->info("{$count} mərhələ \"Gecikib\" statusuna keçirildi.");

        return self::SUCCESS;
    }
}
