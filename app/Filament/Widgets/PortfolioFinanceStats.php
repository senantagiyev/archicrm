<?php

namespace App\Filament\Widgets;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Services\Finance\ProfitabilityService;
use App\Support\AccessMatrix;
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

    /**
     * İcazə rolun adından yox, matrisdən oxunur — bu vidjetin sahibi olan
     * Profitability səhifəsi ilə EYNİ şərt (Profitability::canAccess).
     * Sabit `role === accountant` yoxlaması iki tərəfə də yanlış idi: bütün
     * domenləri bağlanmış, amma bazada `role='accountant'` qalmış istifadəçi
     * portfel gəlirini görməyə davam edirdi, matrisdə Ödənişlər = Tam verilmiş
     * xüsusi rol isə vidjeti heç görmürdü — rol konstruktoru burada ölü idi.
     *
     * Analitika = Baxış + Ödənişlər = Tam cütü məhz sahibkar və mühasibi
     * ayırır: layihə menecerinin Analitika = Baxış icazəsi öz layihələri
     * üzrədir və onun Ödənişlər səviyyəsi yalnız Baxışdır.
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
                // Satınalma sifarişləri də bu kartın içindədir
                // (`portfolio()['cost']`), köhnə «Əmək + xərclər» yazısı isə
                // kartı aşağıdaki cədvəllə tutuşduranı çaşdırırdı.
                ->description('Əmək + xərclər + satınalma — bütün dövr')
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
