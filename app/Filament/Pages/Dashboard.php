<?php

namespace App\Filament\Pages;

use App\Enums\ApprovalStatus;
use App\Enums\ClientStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Approval;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Task;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Bespoke ARCHI dashboard — a hand-designed overview (welcome, KPI tiles, activity
 * chart, status donut, recent projects, today's tasks) instead of the default
 * Filament widget grid, so the panel reads as a product, not a template.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'İdarəetmə Paneli';

    protected static ?string $title = 'İdarəetmə Paneli';

    protected string $view = 'filament.pages.dashboard';

    /** Hide the default page title — the view renders its own welcome header. */
    public function getHeading(): string
    {
        return '';
    }

    /** No default widgets — the custom view renders everything. */
    public function getWidgets(): array
    {
        return [];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public function greetingName(): string
    {
        $name = trim((string) (auth()->user()?->name ?? ''));

        return $name !== '' ? explode(' ', $name)[0] : 'İstifadəçi';
    }

    public function todayLabel(): string
    {
        $days = ['Bazar', 'Bazar ertəsi', 'Çərşənbə axşamı', 'Çərşənbə', 'Cümə axşamı', 'Cümə', 'Şənbə'];
        $months = ['', 'Yanvar', 'Fevral', 'Mart', 'Aprel', 'May', 'İyun', 'İyul', 'Avqust', 'Sentyabr', 'Oktyabr', 'Noyabr', 'Dekabr'];
        $now = Carbon::now();

        return $now->day.' '.$months[$now->month].' '.$now->year.', '.$days[$now->dayOfWeek];
    }

    /** @return array<int, array{label:string, value:string, hint:string, tone:string}> */
    public function stats(): array
    {
        $activeProjects = Project::where('status', ProjectStatus::Active->value)->count();
        $newProjectsWeek = Project::where('created_at', '>=', now()->subWeek())->count();

        $activeClients = Client::where('status', ClientStatus::Client->value)->count();
        $newLeadsWeek = Client::where('status', ClientStatus::Lead->value)->where('created_at', '>=', now()->subWeek())->count();

        $pendingApprovals = Approval::where('status', ApprovalStatus::Pending->value)->count();
        $overdueTasks = Task::whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->whereDate('deadline', '<', today())->count();

        $monthRevenue = (float) Payment::where('status', PaymentStatus::Paid->value)
            ->where('paid_at', '>=', now()->startOfMonth())->sum('amount');

        return [
            ['label' => 'Aktiv layihələr', 'value' => (string) $activeProjects, 'hint' => "+{$newProjectsWeek} bu həftə", 'tone' => 'ink'],
            ['label' => 'Aktiv müştərilər', 'value' => (string) $activeClients, 'hint' => "+{$newLeadsWeek} yeni lid", 'tone' => 'ink'],
            ['label' => 'Gözləyən razılaşdırmalar', 'value' => (string) $pendingApprovals, 'hint' => $pendingApprovals > 0 ? 'cavab gözləyir' : 'hamısı təmiz', 'tone' => $pendingApprovals > 0 ? 'warn' : 'ok'],
            ['label' => 'Bu ay gəlir', 'value' => number_format($monthRevenue, 0, '.', ' ').' ₼', 'hint' => $overdueTasks > 0 ? "{$overdueTasks} gecikmiş tapşırıq" : 'gecikmə yoxdur', 'tone' => 'ink'],
        ];
    }

    /** Projects created per month, last 6 months → bar chart. @return array<int, array{label:string, value:int}> */
    public function activitySeries(): array
    {
        $months = ['', 'Yan', 'Fev', 'Mar', 'Apr', 'May', 'İyn', 'İyl', 'Avq', 'Sen', 'Okt', 'Noy', 'Dek'];
        $out = [];

        for ($i = 5; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $count = Project::whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)->count();
            $out[] = ['label' => $months[$m->month], 'value' => $count];
        }

        return $out;
    }

    /** Projects by status → donut. @return array{total:int, slices: array<int, array{label:string, value:int, color:string}>} */
    public function statusBreakdown(): array
    {
        $palette = [
            ProjectStatus::Active->value => ['Aktiv', '#f2d900'],
            ProjectStatus::Done->value => ['Tamamlanıb', '#22c55e'],
            ProjectStatus::OnHold->value => ['Gözləmədə', '#9ca3af'],
            ProjectStatus::Archived->value => ['Arxiv', '#3f3f46'],
        ];

        $slices = [];
        $total = 0;
        foreach ($palette as $value => [$label, $color]) {
            $count = Project::where('status', $value)->count();
            if ($count > 0) {
                $slices[] = ['label' => $label, 'value' => $count, 'color' => $color];
                $total += $count;
            }
        }

        return ['total' => $total, 'slices' => $slices];
    }

    /** @return Collection<int, Project> */
    public function recentProjects()
    {
        return Project::with('client')->latest()->limit(5)->get();
    }

    /** @return Collection<int, Task> */
    public function todayTasks()
    {
        return Task::with('project')
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
            ->whereDate('deadline', '<=', today())
            ->orderBy('deadline')
            ->limit(6)
            ->get();
    }
}
