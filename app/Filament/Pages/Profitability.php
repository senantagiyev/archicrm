<?php

namespace App\Filament\Pages;

use App\Enums\StaffRole;
use App\Filament\Widgets\CashForecastWidget;
use App\Filament\Widgets\PortfolioFinanceStats;
use App\Filament\Widgets\ProfitabilityWidget;
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

    public static function canAccess(): bool
    {
        $role = auth()->user()?->role;

        return auth()->user()?->isOwner() || $role === StaffRole::Accountant;
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
