<?php

namespace App\Observers;

use App\Enums\StaffRole;
use App\Models\BudgetLine;
use App\Models\Payment;
use App\Models\ProcurementItem;
use App\Models\User;
use App\Notifications\AutomationAlert;
use App\Services\Automation\AutomationEngine;
use App\Services\Finance\ProjectFinanceService;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared observer for the three models whose changes move the client debt.
 * Also emits Əlavə B rule 28 (payment created → notify accounting).
 */
class FinanceObserver
{
    public function __construct(private readonly ProjectFinanceService $finance) {}

    public function created(Model $model): void
    {
        // Rule 28: a new payment record → tell accounting (gated by the Admin toggle).
        if ($model instanceof Payment && app(AutomationEngine::class)->isEnabled('rule-28')) {
            $model->loadMissing('project');

            $recipients = User::query()
                ->where('is_active', true)
                ->whereIn('role', [StaffRole::Owner->value, StaffRole::Accountant->value])
                ->get();

            foreach ($recipients as $user) {
                $user->notify(new AutomationAlert(
                    'Yeni ödəniş qeydə alındı',
                    ($model->title ? $model->title.' — ' : '').number_format((float) $model->amount, 2).' ₼'
                        .($model->project ? ' ('.$model->project->name.')' : ''),
                    null,
                    ['payment_id' => $model->id, 'project_id' => $model->project_id],
                    'rule-28',
                ));
            }
        }
    }

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
