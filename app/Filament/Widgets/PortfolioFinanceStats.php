<?php

namespace App\Filament\Widgets;

use App\Enums\StaffRole;
use App\Services\Finance\ProfitabilityService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Portfolio finance snapshot (TZ §8.1 owner block): the ≤5-minute picture —
 * collected, receivable, overdue, cost, profit, margin, projected. Owner/accountant only.
 */
class PortfolioFinanceStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $role = auth()->user()?->role;

        return auth()->user()?->isOwner() || $role === StaffRole::Accountant;
    }

    protected function getStats(): array
    {
        $p = app(ProfitabilityService::class)->portfolio();

        // Two decimals, like the table below. Rounding to whole manat here meant
        // the cards never footed to the per-project rows an accountant checks.
        $fmt = fn (float $v) => number_format($v, 2, '.', ' ').' ₼';

        return [
            // The period is spelled out: these three are all-time (archive
            // included), while "Proqnoz gəlir" and the table below are
            // active-only. Unlabelled, the two never reconciled.
            Stat::make('Yığılmış gəlir', $fmt($p['collected']))
                ->description('Faktiki ödənişlər — bütün dövr')
                ->color('success'),
            Stat::make('Debitor borc', $fmt($p['receivable']))
                ->description('Ödənilməmiş hesab-fakturalar')
                ->color($p['receivable'] > 0 ? 'warning' : 'gray'),
            Stat::make('Gecikmiş', $fmt($p['overdue']))
                ->description('Vaxtı keçmiş qalıq')
                ->color($p['overdue'] > 0 ? 'danger' : 'gray'),
            Stat::make('Xərc', $fmt($p['cost']))
                ->description('Əmək + xərclər — bütün dövr')
                ->color('gray'),
            Stat::make('Mənfəət', $fmt($p['gross_profit']))
                // null margin = no revenue to measure against; «0%» would read as
                // breaking even on a portfolio that only spent money.
                ->description($p['margin'] === null ? 'Marja: — (gəlir yoxdur)' : 'Marja: '.$p['margin'].'%')
                ->color($p['gross_profit'] >= 0 ? 'success' : 'danger'),
            Stat::make('Proqnoz gəlir', $fmt($p['projected']))
                ->description('Aktiv layihələrin plan büdcəsi')
                ->color('info'),
        ];
    }
}
