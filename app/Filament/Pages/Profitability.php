<?php

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Filament\Widgets\CashForecastWidget;
use App\Filament\Widgets\PortfolioFinanceStats;
use App\Filament\Widgets\ProfitabilityWidget;
use App\Support\AccessMatrix;
use Filament\Pages\Page;

/**
 * Rentabellik hesabatı (TZ §8.24): one screen giving the owner the whole finance
 * picture in ≤5 minutes — portfolio snapshot, cash-in forecast, per-project P&L.
 */
class Profitability extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Maliyyə';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Rentabellik';

    protected static ?string $title = 'Rentabellik hesabatı';

    protected string $view = 'filament.pages.profitability';

    /**
     * Read from the matrix, not from a hardcoded role name: an owner who builds a
     * custom "finance analyst" role with Analytics = Full expects it to work, and
     * a role name check silently ignores the whole role constructor.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Portfolio-wide P&L belongs to whoever owns the money domain. A project
        // manager's Analytics = View is scoped to their own projects, so Payments
        // = Full is what separates them from the owner and the accountant.
        return $user !== null
            && AccessMatrix::allows($user, Domain::Analytics, AccessLevel::View)
            && AccessMatrix::allows($user, Domain::Payments, AccessLevel::Full);
    }

    public function getHeaderWidgets(): array
    {
        return [
            PortfolioFinanceStats::class,
            CashForecastWidget::class,
            ProfitabilityWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
