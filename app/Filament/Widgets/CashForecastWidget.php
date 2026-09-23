<?php

namespace App\Filament\Widgets;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Services\Finance\ProfitabilityService;
use App\Support\AccessMatrix;
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

    /**
     * Gözlənilən pul daxilolmaları — açıq maliyyə məlumatıdır, ona görə şərt
     * Profitability səhifəsi və PortfolioFinanceStats ilə eynidir: matrisdən
     * Analitika = Baxış + Ödənişlər = Tam. Sabit rol adı burada da həm xüsusi
     * rolu bağlayır, həm də köhnə `role` sütunu ilə qalmış istifadəçiyə
     * icazəsiz giriş verirdi.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessMatrix::allows($user, Domain::Analytics, AccessLevel::View)
            && AccessMatrix::allows($user, Domain::Payments, AccessLevel::Full);
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
            // Without this card the buckets did not add up to the money owed:
            // undated payments were dropped from the forecast entirely.
            Stat::make('Tarixsiz', $fmt($f['undated']))
                ->description('Plan tarixi təyin olunmayıb')
                ->color($f['undated'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
