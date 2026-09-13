<?php

namespace App\Services\Finance;

use App\Enums\ApprovalStatus;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;

/**
 * TZ v2.0 §7.26 / §8.24 — profitability and finance forecast computed online from
 * Payments, Expenses and TimeEntry labour (never stored as its own table).
 */
class ProfitabilityService
{
    /** @var array<int,array> Per-request memo so a table row is computed once, not per column. */
    private array $cache = [];

    /** @return array{revenue: float, labor_cost: float, expenses: float, purchases: float, cost: float, gross_profit: float, margin: float|null, uncosted_minutes: int, uncosted_procurement: float, projected_revenue: float} */
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

        // Rejected claims are money the studio refused to pay; including them
        // inflated cost and understated every margin on the report.
        $expenses = (float) $project->expenses()
            ->whereIn('status', ExpenseStatus::costBearing())
            ->sum('amount');

        // What the studio pays suppliers. Purchase orders used to feed nothing at
        // all, so a furniture-heavy job billed the client thousands and showed
        // zero cost — the most profitable-looking work in the studio.
        $purchases = (float) $project->purchaseOrders()
            ->whereIn('status', PurchaseOrderStatus::costBearing())
            ->sum('total');

        // Hours logged by someone whose rate is 0 contribute nothing, which
        // silently reads as a higher margin. Surface them so the report can say
        // so instead of quietly flattering the project.
        $uncostedMinutes = (int) $project->timeEntries()
            ->where(fn ($query) => $query->whereNull('hourly_cost_snapshot')->orWhere('hourly_cost_snapshot', 0))
            ->sum('duration_minutes');

        // Procurement billed to the client with nothing recorded on the paying
        // side. The report cannot invent that cost, but it must not present the
        // resulting margin as if the goods were free.
        $billedProcurement = (float) $project->procurementItems()
            ->where('approval_status', ApprovalStatus::Approved->value)
            ->where('purchase_status', '!=', PurchaseStatus::Cancelled->value)
            ->sum('total');

        $uncostedProcurement = $purchases > 0 || $expenses > 0 ? 0.0 : round($billedProcurement, 2);

        $cost = round($laborCost + $expenses + $purchases, 2);
        $grossProfit = round($revenue - $cost, 2);

        return $this->cache[$project->id] = [
            'revenue' => round($revenue, 2),
            'labor_cost' => round($laborCost, 2),
            'expenses' => round($expenses, 2),
            'purchases' => round($purchases, 2),
            'cost' => $cost,
            'gross_profit' => $grossProfit,
            'margin' => self::margin($revenue, $grossProfit),
            'uncosted_minutes' => $uncostedMinutes,
            'uncosted_procurement' => $uncostedProcurement,
            'projected_revenue' => round((float) ($project->budget_plan ?? 0), 2),
        ];
    }

    /**
     * Null when there is no revenue to measure against: a project that spent 750
     * and collected nothing is not a 0% margin, which reads as breaking even.
     * Callers render null as «—» next to the (negative) gross profit.
     */
    public static function margin(float $revenue, float $grossProfit): ?float
    {
        return $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : null;
    }

    /**
     * Portfolio-wide finance snapshot (TZ §8.1 owner finance block). Computed with
     * a handful of aggregate queries — independent of project count.
     *
     * @return array{collected: float, receivable: float, overdue: float, cost: float, gross_profit: float, margin: float|null, projected: float}
     */
    public function portfolio(): array
    {
        // Drop only money whose project was DELETED — payments, time entries and
        // expenses are not soft-deleted, so a deleted project's figures used to
        // linger in `collected` and `cost` while `projected` dropped them.
        //
        // A row with no project at all is a different thing entirely: studio rent,
        // software, travel. `whereHas('project')` alone excluded those too, which
        // erased the studio's whole overhead from cost and flattered the margin.
        $live = fn ($query) => $query->where(
            fn ($inner) => $inner->whereNull('project_id')->orWhereHas('project')
        );

        $collected = (float) $live(Payment::query())
            ->where('status', PaymentStatus::Paid->value)
            ->sum('amount');

        $labor = (float) $live(TimeEntry::query())
            ->selectRaw('COALESCE(SUM(duration_minutes * hourly_cost_snapshot) / 60, 0) as c')
            ->value('c');

        $expenses = (float) $live(Expense::query())
            ->whereIn('status', ExpenseStatus::costBearing())
            ->sum('amount');

        $purchases = (float) $live(PurchaseOrder::query())
            ->whereIn('status', PurchaseOrderStatus::costBearing())
            ->sum('total');

        // Receivable = unpaid balance on live invoices; overdue = that balance past due.
        $unpaidStatuses = ['issued', 'sent', 'partially_paid', 'overdue'];
        $receivable = (float) $live(Invoice::query())
            ->whereIn('status', $unpaidStatuses)
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as r')
            ->value('r');

        $overdue = (float) $live(Invoice::query())
            ->whereIn('status', $unpaidStatuses)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as r')
            ->value('r');

        $projected = (float) Project::query()
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->sum('budget_plan');

        $cost = round($labor + $expenses + $purchases, 2);
        $grossProfit = round($collected - $cost, 2);
        $margin = self::margin($collected, $grossProfit);

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
     * @return array{overdue: float, d30: float, d60: float, d90: float, later: float, undated: float}
     */
    public function cashForecast(): array
    {
        $pending = Payment::query()
            // Same live-project restriction as portfolio(): forecasting cash from
            // a deleted project's payment schedule is forecasting money nobody
            // is going to send.
            ->whereHas('project')
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Overdue->value])
            ->get(['amount', 'due_date']);

        // `undated` exists so the buckets add up to the money actually owed.
        // Filtering out payments with no due date made the forecast silently
        // short by however much of the schedule had not been dated yet.
        $buckets = ['overdue' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'later' => 0.0, 'undated' => 0.0];

        foreach ($pending as $payment) {
            $amount = (float) $payment->amount;

            if ($payment->due_date === null) {
                $buckets['undated'] += $amount;

                continue;
            }

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
