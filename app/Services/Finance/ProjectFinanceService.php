<?php

namespace App\Services\Finance;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Models\Project;

/**
 * TZ §5.10: client debt = (approved smeta + approved procurement) − confirmed
 * payments; recalculated whenever any component changes (observers call this).
 * The result lives in the cached projects.debt column.
 */
class ProjectFinanceService
{
    public function recalculateDebt(Project $project): void
    {
        $approvedBudget = $project->budgetLines()
            ->where('approval_status', ApprovalStatus::Approved->value)
            ->sum('total');

        $approvedProcurement = $project->procurementItems()
            ->where('approval_status', ApprovalStatus::Approved->value)
            ->where('purchase_status', '!=', PurchaseStatus::Cancelled->value)
            ->sum('total');

        $paid = $project->payments()
            ->where('status', PaymentStatus::Paid->value)
            ->sum('amount');

        $debt = round((float) $approvedBudget + (float) $approvedProcurement - (float) $paid, 2);

        if ((float) $project->debt !== $debt) {
            $project->forceFill(['debt' => $debt])->saveQuietly();
        }
    }
}
