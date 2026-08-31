<?php

namespace App\Observers;

use App\Models\BudgetLine;
use App\Models\Payment;
use App\Models\ProcurementItem;
use App\Services\Finance\ProjectFinanceService;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared observer for the three models whose changes move the client debt.
 */
class FinanceObserver
{
    public function __construct(private readonly ProjectFinanceService $finance) {}

    public function saved(Model $model): void
    {
        $this->recalculate($model);
    }

    public function deleted(Model $model): void
    {
        $this->recalculate($model);
    }

    private function recalculate(BudgetLine|Payment|ProcurementItem|Model $model): void
    {
        $model->loadMissing('project');

        if ($model->project) {
            $this->finance->recalculateDebt($model->project);
        }
    }
}
