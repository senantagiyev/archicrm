<?php

namespace App\Filament\Widgets;

use App\Enums\ApprovalStatus;
use App\Enums\ClientStatus;
use App\Enums\ProjectStatus;
use App\Enums\StaffRole;
use App\Enums\TaskStatus;
use App\Models\Approval;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Rəhbər dashboardu — layihələr, pullar, gecikmələr, lidlər (TZ §5.7). */
class OwnerStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->isOwner()
            || auth()->user()?->role === StaffRole::Accountant;
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
