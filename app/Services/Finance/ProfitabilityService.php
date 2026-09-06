<?php

namespace App\Services\Finance;

use App\Enums\PaymentStatus;
use App\Models\Project;

/**
 * TZ v2.0 §7.26 / §8.24 — profitability computed online from Payments, Expenses
 * and TimeEntry labour (never stored as its own table).
 */
class ProfitabilityService
{
    /** @return array{revenue: float, labor_cost: float, expenses: float, cost: float, gross_profit: float, margin: float, projected_revenue: float} */
    public function forProject(Project $project): array
    {
        $revenue = (float) $project->payments()
            ->where('status', PaymentStatus::Paid->value)
            ->sum('amount');

        $laborCost = (float) $project->timeEntries()
            ->selectRaw('COALESCE(SUM(duration_minutes * hourly_cost_snapshot) / 60, 0) as c')
            ->value('c');

        $expenses = (float) $project->expenses()->sum('amount');

        $cost = round($laborCost + $expenses, 2);
        $grossProfit = round($revenue - $cost, 2);
        $margin = $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : 0.0;

        return [
            'revenue' => round($revenue, 2),
            'labor_cost' => round($laborCost, 2),
            'expenses' => round($expenses, 2),
            'cost' => $cost,
            'gross_profit' => $grossProfit,
            'margin' => $margin,
            'projected_revenue' => (float) ($project->contract_value ?? 0),
        ];
    }
}
