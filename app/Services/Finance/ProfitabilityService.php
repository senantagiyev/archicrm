<?php

namespace App\Services\Finance;

use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\TimeEntry;

/**
 * TZ v2.0 §7.26 / §8.24 — profitability and finance forecast computed online from
 * Payments, Expenses and TimeEntry labour (never stored as its own table).
 */
class ProfitabilityService
{
    /** @var array<int,array> Per-request memo so a table row is computed once, not per column. */
    private array $cache = [];

    /** @return array{revenue: float, labor_cost: float, expenses: float, cost: float, gross_profit: float, margin: float, projected_revenue: float} */
    public function forProject(Project $project): array
    {
        if (isset($this->cache[$project->id])) {
            return $this->cache[$project->id];
        }

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

        return $this->cache[$project->id] = [
            'revenue' => round($revenue, 2),
            'labor_cost' => round($laborCost, 2),
            'expenses' => round($expenses, 2),
            'cost' => $cost,
            'gross_profit' => $grossProfit,
            'margin' => $margin,
            'projected_revenue' => round((float) ($project->budget_plan ?? 0), 2),
        ];
    }

    /**
     * Portfolio-wide finance snapshot (TZ §8.1 owner finance block). Computed with
     * a handful of aggregate queries — independent of project count.
     *
     * @return array{collected: float, receivable: float, overdue: float, cost: float, gross_profit: float, margin: float, projected: float}
     */
    public function portfolio(): array
    {
        $collected = (float) Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->sum('amount');

        $labor = (float) TimeEntry::query()
            ->selectRaw('COALESCE(SUM(duration_minutes * hourly_cost_snapshot) / 60, 0) as c')
            ->value('c');

        $expenses = (float) Expense::query()->sum('amount');

        // Receivable = unpaid balance on live invoices; overdue = that balance past due.
        $unpaidStatuses = ['issued', 'sent', 'partially_paid', 'overdue'];
        $receivable = (float) Invoice::query()
            ->whereIn('status', $unpaidStatuses)
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as r')
            ->value('r');

        $overdue = (float) Invoice::query()
            ->whereIn('status', $unpaidStatuses)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as r')
            ->value('r');

        $projected = (float) Project::query()
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->sum('budget_plan');

        $cost = round($labor + $expenses, 2);
        $grossProfit = round($collected - $cost, 2);
        $margin = $collected > 0 ? round($grossProfit / $collected * 100, 1) : 0.0;

        return [
            'collected' => round($collected, 2),
            'receivable' => round($receivable, 2),
            'overdue' => round($overdue, 2),
            'cost' => $cost,
            'gross_profit' => $grossProfit,
            'margin' => $margin,
            'projected' => round($projected, 2),
        ];
    }

    /**
     * Expected cash-in from pending/overdue payments, bucketed by due window.
     * "overdue" is money already past its plan date and still not collected.
     *
     * @return array{overdue: float, d30: float, d60: float, d90: float, later: float}
     */
    public function cashForecast(): array
    {
        $pending = Payment::query()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Overdue->value])
            ->whereNotNull('due_date')
            ->get(['amount', 'due_date']);

        $buckets = ['overdue' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'later' => 0.0];

        foreach ($pending as $payment) {
            $amount = (float) $payment->amount;
            $days = today()->diffInDays($payment->due_date, false); // signed: negative = past

            if ($days < 0) {
                $buckets['overdue'] += $amount;
            } elseif ($days <= 30) {
                $buckets['d30'] += $amount;
            } elseif ($days <= 60) {
                $buckets['d60'] += $amount;
            } elseif ($days <= 90) {
                $buckets['d90'] += $amount;
            } else {
                $buckets['later'] += $amount;
            }
        }

        return array_map(fn ($v) => round($v, 2), $buckets);
    }
}
