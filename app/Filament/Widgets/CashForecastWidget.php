<?php

namespace App\Filament\Widgets;

use App\Enums\StaffRole;
use App\Services\Finance\ProfitabilityService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Cash-in forecast (TZ §8.24 Profitability/Forecast): expected incoming money from
 * pending payments, bucketed by due window. Owner/accountant only.
 */
class CashForecastWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return 'Pul axını proqnozu';
    }

    public static function canView(): bool
    {
        $role = auth()->user()?->role;

        return auth()->user()?->isOwner() || $role === StaffRole::Accountant;
    }

    protected function getStats(): array
    {
        $f = app(ProfitabilityService::class)->cashForecast();
        $fmt = fn (float $v) => number_format($v, 0, '.', ' ').' ₼';

        return [
            Stat::make('Gecikmiş', $fmt($f['overdue']))
                ->description('Vaxtı keçib')
                ->color($f['overdue'] > 0 ? 'danger' : 'gray'),
            Stat::make('30 gün', $fmt($f['d30']))
                ->description('Növbəti 30 gün')
                ->color('success'),
            Stat::make('60 gün', $fmt($f['d60']))
                ->description('31–60 gün')
                ->color('info'),
            Stat::make('90 gün', $fmt($f['d90']))
                ->description('61–90 gün')
                ->color('info'),
            Stat::make('Sonra', $fmt($f['later']))
                ->description('90 gündən sonra')
                ->color('gray'),
        ];
    }
}
