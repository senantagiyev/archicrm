<?php

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\ApprovalStatus;
use App\Enums\ClientStatus;
use App\Enums\Domain;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Approval;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Task;
use App\Support\AccessMatrix;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Database\Eloquent\Builder;
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

    /** @var array<int, int>|null Görünən layihə id-ləri (null = məhdudiyyət yoxdur). */
    private ?array $visibleProjectIds = null;

    private bool $visibleProjectIdsResolved = false;

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

    /**
     * The panel home stays open to everyone — it is where login lands — but each
     * tile is gated by its own domain. Revenue used to greet a visualizer whose
     * matrix says Payments = None.
     *
     * @return array<int, array{label:string, value:string, hint:string, tone:string}>
     */
    public function stats(): array
    {
        $user = auth()->user();
        $tiles = [];

        // Rəqəm də «öz layihələrim» üzrə olmalıdır: gecikmə sayı yad layihələrin
        // tapşırıqlarını da sayanda dizayner üçün mənasız, üstəlik studiyanın
        // ümumi vəziyyəti barədə məlumat sızdıran bir göstəriciyə çevrilirdi.
        $overdueTasks = $this->scoped(
            Task::query()
                ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
                ->whereDate('deadline', '<', today())
        )->count();

        if ($this->mayRead(Domain::Projects)) {
            // Sayğac da «öz layihələrim» üzrədir: studiyanın ümumi layihə sayı
            // iki layihədə işləyən dizayner üçün həm yanıltıcıdır, həm də büro
            // haqqında ona aid olmayan göstəricidir.
            $activeProjects = $this->scopeProjects(
                Project::query()->where('status', ProjectStatus::Active->value)
            )->count();
            $newProjectsWeek = $this->scopeProjects(
                Project::query()->where('created_at', '>=', now()->subWeek())
            )->count();

            $tiles[] = ['label' => 'Aktiv layihələr', 'value' => (string) $activeProjects, 'hint' => "+{$newProjectsWeek} bu həftə", 'tone' => 'ink'];
        }

        if ($this->mayRead(Domain::Clients)) {
            $activeClients = Client::where('status', ClientStatus::Client->value)->count();
            $newLeadsWeek = Client::where('status', ClientStatus::Lead->value)->where('created_at', '>=', now()->subWeek())->count();

            $tiles[] = ['label' => 'Aktiv müştərilər', 'value' => (string) $activeClients, 'hint' => "+{$newLeadsWeek} yeni lid", 'tone' => 'ink'];
        }

        if ($user?->can('viewAny', Approval::class)) {
            $pendingApprovals = Approval::where('status', ApprovalStatus::Pending->value)->count();

            $tiles[] = ['label' => 'Gözləyən razılaşdırmalar', 'value' => (string) $pendingApprovals, 'hint' => $pendingApprovals > 0 ? 'cavab gözləyir' : 'hamısı təmiz', 'tone' => $pendingApprovals > 0 ? 'warn' : 'ok'];
        }

        if ($this->mayRead(Domain::Payments)) {
            $monthRevenue = (float) Payment::where('status', PaymentStatus::Paid->value)
                ->where('paid_at', '>=', now()->startOfMonth())->sum('amount');

            $tiles[] = ['label' => 'Bu ay gəlir', 'value' => number_format($monthRevenue, 0, '.', ' ').' ₼', 'hint' => $overdueTasks > 0 ? "{$overdueTasks} gecikmiş tapşırıq" : 'gecikmə yoxdur', 'tone' => 'ink'];
        }

        $tiles[] = ['label' => 'Gecikmiş tapşırıqlar', 'value' => (string) $overdueTasks, 'hint' => $overdueTasks > 0 ? 'diqqət tələb edir' : 'hamısı vaxtında', 'tone' => $overdueTasks > 0 ? 'warn' : 'ok'];

        return $tiles;
    }

    public function mayRead(Domain $domain): bool
    {
        $user = auth()->user();

        return $user !== null && AccessMatrix::allows($user, $domain, AccessLevel::View);
    }

    /** Projects created per month, last 6 months → bar chart. @return array<int, array{label:string, value:int}> */
    public function activitySeries(): array
    {
        $months = ['', 'Yan', 'Fev', 'Mar', 'Apr', 'May', 'İyn', 'İyl', 'Avq', 'Sen', 'Okt', 'Noy', 'Dek'];
        $out = [];

        for ($i = 5; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            // Qrafik də tile-lar və siyahılarla eyni toplumu göstərməlidir —
            // əks halda dizayner 2 layihə görür, sütunlar isə 14-ü sayır.
            $count = $this->scopeProjects(
                Project::query()->whereYear('created_at', $m->year)->whereMonth('created_at', $m->month)
            )->count();
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
            $count = $this->scopeProjects(Project::query()->where('status', $value))->count();
            if ($count > 0) {
                $slices[] = ['label' => $label, 'value' => $count, 'color' => $color];
                $total += $count;
            }
        }

        return ['total' => $total, 'slices' => $slices];
    }

    /**
     * «Son layihələr» siyahısı. Burada layihənin ADI və müştərisi görünür, ona
     * görə tile-lardakı domen yoxlaması kifayət etmir: dizayner/vizualizator
     * Layihələr = Redaktə səviyyəsinə malikdir, amma bu səviyyə yalnız ÖZ
     * layihələrinə aiddir (matrisdəki «own projects» şərti).
     *
     * @return Collection<int, Project>
     */
    public function recentProjects()
    {
        return $this->scopeProjects(Project::query()->with('client')->latest())
            ->limit(5)
            ->get();
    }

    /**
     * Tapşırıq BAŞLIQLARI göstərilir — müştərinin şikayət etdiyi sızma məhz
     * budur. Siyahı eyni «öz layihələrim» filtri ilə kəsilir.
     *
     * @return Collection<int, Task>
     */
    public function todayTasks()
    {
        return $this->scoped(
            Task::query()
                ->with('project')
                ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
                ->whereDate('deadline', '<=', today())
        )
            ->orderBy('deadline')
            ->limit(6)
            ->get();
    }

    /**
     * Layihəyə bağlı modelləri istifadəçinin görə bildiyi layihələrlə
     * məhdudlaşdırır (Attention ekranı ilə eyni məntiq). Studiya izolyasiyası
     * BelongsToTenant qlobal skopundan gəlir; bu isə matrisin «öz layihələri»
     * şərtidir — biri studiyanı, digəri layihəni kəsir, bir-birini əvəz etmir.
     */
    private function scoped(Builder $query): Builder
    {
        $ids = $this->accessibleProjectIds();

        return $ids === null ? $query : $query->whereIn('project_id', $ids);
    }

    /** Eyni məhdudiyyətin `projects` cədvəlinin öz üzərində variantı. */
    private function scopeProjects(Builder $query): Builder
    {
        $ids = $this->accessibleProjectIds();

        return $ids === null ? $query : $query->whereKey($ids);
    }

    /**
     * @return array<int, int>|null null = bütün layihələr (sahibkar, mühasib)
     */
    private function accessibleProjectIds(): ?array
    {
        // Bir səhifə açılışında stats(), recentProjects() və todayTasks() eyni
        // siyahını soruşur — bir dəfə hesablanır.
        if ($this->visibleProjectIdsResolved) {
            return $this->visibleProjectIds;
        }

        $this->visibleProjectIdsResolved = true;
        $user = auth()->user();

        if ($user === null || ! AccessMatrix::requiresOwnProject($user)) {
            return $this->visibleProjectIds = null;
        }

        return $this->visibleProjectIds = Project::query()
            ->where(fn (Builder $q) => $q
                ->where('manager_user_id', $user->id)
                ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->id)))
            ->pluck('id')
            ->all();
    }
}
