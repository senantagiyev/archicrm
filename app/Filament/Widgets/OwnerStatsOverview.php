<?php

namespace App\Filament\Widgets;

use App\Enums\AccessLevel;
use App\Enums\ApprovalStatus;
use App\Enums\ClientStatus;
use App\Enums\Domain;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Approval;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Support\AccessMatrix;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Rəhbər dashboardu — layihələr, pullar, gecikmələr, lidlər (TZ §5.7). */
class OwnerStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /**
     * Bu vidjet elə «Rəhbər paneli» blokunun özüdür (TZ §5.7), ona görə matrisdə
     * ona uyğun gələn domen də məhz Rəhbər paneli-dir: sahibkarda Tam, mühasibdə
     * Baxış (maliyyə ilə məhdud), qalan beş rolda Yoxdur — yəni standart rolların
     * mövcud davranışı hərfi-hərfinə saxlanılır. Fərq ondadır ki, indi domeni
     * bağlanmış «mühasib» rolu vidjeti itirir, Rəhbər paneli = Baxış verilmiş
     * xüsusi rol isə onu qazanır.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessMatrix::allows($user, Domain::OwnerDashboard, AccessLevel::View);
    }

    protected function getStats(): array
    {
        $activeProjects = Project::where('status', ProjectStatus::Active->value)->count();

        $totalDebt = Project::whereNotIn('status', [ProjectStatus::Archived->value])->sum('debt');

        $overdueTasks = Task::whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->whereDate('deadline', '<', today())
            ->count();

        $newLeads = Client::where('status', ClientStatus::Lead->value)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $pendingApprovals = Approval::where('status', ApprovalStatus::Pending->value)->count();

        return [
            Stat::make('Aktiv layihələr', $activeProjects),
            Stat::make('Ümumi borc', number_format((float) $totalDebt, 0, '.', ' ').' ₼')
                ->color($totalDebt > 0 ? 'warning' : 'success'),
            Stat::make('Gecikmiş tapşırıqlar', $overdueTasks)
                ->color($overdueTasks > 0 ? 'danger' : 'success'),
            Stat::make('Yeni lidlər (30 gün)', $newLeads),
            Stat::make('Gözləyən razılaşdırmalar', $pendingApprovals)
                ->color($pendingApprovals > 0 ? 'warning' : 'gray'),
        ];
    }
}
