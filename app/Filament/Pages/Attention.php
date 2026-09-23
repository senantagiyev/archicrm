<?php

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\ApprovalStatus;
use App\Enums\BriefStatus;
use App\Enums\Domain;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\ApprovalResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TaskResource;
use App\Models\Approval;
use App\Models\Brief;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Stage;
use App\Models\Task;
use App\Services\Brief\BriefRiskDetector;
use App\Support\AccessMatrix;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Bu gün nəyə diqqət lazımdır» — Roomix-in `/analytics` ekranının qarşılığı.
 *
 * Məqsəd: səhər panelə girən adam bir ekranda «əlini nəyə atmalıdır» sualına
 * cavab alsın. Ona görə burada YENİ məlumat saxlanmır — hər blok mövcud
 * modellərin üstündə oxu sorğusudur (razılaşdırma, mərhələ, tapşırıq, ödəniş,
 * brif, brif riskləri). Yeni cədvəl və ya sütun əlavə edilmir.
 */
class Attention extends Page
{
    /** Bir blokda göstərilən sətir sayı — qalanı «hamısına bax» keçidindədir. */
    private const PREVIEW_LIMIT = 5;

    /**
     * Risk detektoru brif başına bir neçə sorğu edir, ona görə yalnız ən son
     * aktiv layihələr üçün işləyir. Ekranın açılma vaxtı layihə sayından asılı
     * olmamalıdır — 500 layihəli studiyada da bu blok sabit qiymətə başa gəlir.
     */
    private const RISK_PROJECT_LIMIT = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Diqqət tələb edir';

    protected static ?string $title = 'Bu gün nəyə diqqət lazımdır';

    protected string $view = 'filament.pages.attention';

    /** @var array<int, int>|null Görünən layihə id-ləri (null = məhdudiyyət yoxdur). */
    private ?array $visibleProjectIds = null;

    private bool $visibleProjectIdsResolved = false;

    /** @var array<int, array<string, mixed>>|null Bir sorğu dövründə bir dəfə hesablanır. */
    private ?array $blocks = null;

    /**
     * İcazə rol adından yox, matrisdən oxunur — Profitability ilə eyni yanaşma:
     * sahibkar «analitik» adlı öz rolunu qurduqda ekran işləməlidir.
     *
     * Analytics = View kifayətdir: burada məbləğ yox, iş siyahısı var. Dizayner
     * və vizualizatorun matrisində Analytics = None-dur, deməli onlar bu ekrana
     * düşmür.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessMatrix::allows($user, Domain::Analytics, AccessLevel::View);
    }

    /**
     * Ekranın bütün məzmunu. Hər blok eyni formadadır ki, blade sadə qalsın:
     * başlıq, say, ilk 5 sətir, «hamısına bax» keçidi və boş halda sakit mesaj.
     *
     * @return array<int, array{
     *     key: string, title: string, subtitle: string, count: int,
     *     items: array<int, array{title: string, meta: string, url: ?string, urgent: bool, badge: ?string}>,
     *     url: ?string, empty: string
     * }>
     */
    public function blocks(): array
    {
        // Başlıqdakı ümumi say da eyni nəticədən oxunur — sorğular iki dəfə
        // işləməsin deyə nəticə sorğu dövrü ərzində saxlanılır.
        return $this->blocks ??= [
            $this->pendingApprovalsBlock(),
            $this->overdueStagesBlock(),
            $this->overdueTasksBlock(),
            $this->overduePaymentsBlock(),
            $this->waitingBriefsBlock(),
            $this->briefRisksBlock(),
        ];
    }

    /** Ekranın başında ümumi say — «bu gün neçə iş var» sualının cavabı. */
    public function totalCount(): int
    {
        return array_sum(array_column($this->blocks(), 'count'));
    }

    // ── Bloklar ──────────────────────────────────────────────────────────────

    /**
     * 1. Müştəri cavabını gözləyən razılaşdırmalar.
     * Müddəti keçmişlər (isOverdue) ayrıca vurğulanır və siyahının başına düşür.
     */
    private function pendingApprovalsBlock(): array
    {
        $query = fn (): Builder => $this->scoped(
            Approval::query()->where('status', ApprovalStatus::Pending->value)
        );

        $items = $query()
            // Morph obyektin adı (subjectLabel) və layihə adı blade-də oxunur —
            // lazy loading qadağan olduğuna görə hər ikisi əvvəlcədən yüklənir.
            ->with(['project:id,name', 'approvable'])
            // Müddəti keçənlər öncə: NULL respond_by sona düşməlidir, ona görə
            // sıralama əvvəlcə «tarix varmı» üzrə gedir (sadə orderBy NULL-ları
            // başa qoyardı və gecikmişləri aşağı sıxardı).
            ->orderByRaw('respond_by is null')
            ->orderBy('respond_by')
            ->orderBy('created_at')
            ->limit(self::PREVIEW_LIMIT)
            ->get()
            ->map(fn (Approval $approval): array => [
                'title' => $approval->subjectLabel(),
                'meta' => trim(($approval->project?->name ?? '—').' · '.$approval->daysWaiting().' gündür gözləyir'),
                'url' => $approval->project_id
                    ? ProjectResource::getUrl('edit', ['record' => $approval->project_id])
                    : null,
                'urgent' => $approval->isOverdue(),
                'badge' => $approval->isOverdue() ? 'Cavab müddəti keçib' : null,
            ])
            ->all();

        return [
            'key' => 'approvals',
            'title' => 'Müştəri cavabını gözləyən razılaşdırmalar',
            'subtitle' => 'Cavab müddəti keçənlər qırmızı işarələnib.',
            'count' => $query()->count(),
            'items' => $items,
            'url' => ApprovalResource::getUrl('index'),
            'empty' => 'Cavab gözləyən razılaşdırma yoxdur.',
        ];
    }

    /** 2. Gecikmiş mərhələlər — Stage::isOverdue() ilə eyni şərt, SQL-də. */
    private function overdueStagesBlock(): array
    {
        $query = fn (): Builder => $this->scoped(
            Stage::query()
                ->where('status', '!=', StageStatus::Done->value)
                ->whereNotNull('date_plan_end')
                ->whereDate('date_plan_end', '<', today())
        );

        $items = $query()
            ->with('project:id,name')
            ->orderBy('date_plan_end')
            ->limit(self::PREVIEW_LIMIT)
            ->get()
            ->map(fn (Stage $stage): array => [
                'title' => $stage->name,
                'meta' => ($stage->project?->name ?? '—').' · plan: '.$stage->date_plan_end->format('d.m.Y'),
                'url' => $stage->project_id
                    ? ProjectResource::getUrl('edit', ['record' => $stage->project_id])
                    : null,
                'urgent' => true,
                'badge' => $this->daysLateLabel($stage->date_plan_end),
            ])
            ->all();

        return [
            'key' => 'stages',
            'title' => 'Gecikmiş mərhələlər',
            'subtitle' => 'Plan bitmə tarixi keçib, mərhələ hələ bağlanmayıb.',
            'count' => $query()->count(),
            'items' => $items,
            'url' => ProjectResource::getUrl('index'),
            'empty' => 'Gecikmiş mərhələ yoxdur.',
        ];
    }

    /** 3. Gecikmiş tapşırıqlar — Task::isOverdue() şərtinin SQL qarşılığı. */
    private function overdueTasksBlock(): array
    {
        $query = fn (): Builder => $this->scoped(
            Task::query()
                ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
                ->whereNotNull('deadline')
                ->whereDate('deadline', '<', today())
        );

        $items = $query()
            ->with(['project:id,name', 'assignee:id,name'])
            ->orderBy('deadline')
            ->limit(self::PREVIEW_LIMIT)
            ->get()
            ->map(fn (Task $task): array => [
                'title' => $task->title,
                'meta' => ($task->project?->name ?? '—').' · '.($task->assignee?->name ?? 'təyinatsız'),
                'url' => TaskResource::getUrl('edit', ['record' => $task->getKey()]),
                'urgent' => true,
                'badge' => $this->daysLateLabel($task->deadline),
            ])
            ->all();

        return [
            'key' => 'tasks',
            'title' => 'Gecikmiş tapşırıqlar',
            'subtitle' => 'Son tarixi keçib, hələ bitməyib.',
            'count' => $query()->count(),
            'items' => $items,
            'url' => TaskResource::getUrl('index'),
            'empty' => 'Gecikmiş tapşırıq yoxdur.',
        ];
    }

    /**
     * 4. Gecikmiş ödənişlər.
     * `payments:mark-overdue` əmri gecə işləyir; ekran gün ərzində açıldıqda da
     * düzgün olmalıdır, ona görə statusu «Gecikib» olanlarla yanaşı hələ
     * işarələnməmiş (pending + tarixi keçmiş) sətirlər də sayılır.
     */
    private function overduePaymentsBlock(): array
    {
        $query = fn (): Builder => $this->scoped(
            Payment::query()->where(fn (Builder $q) => $q
                ->where('status', PaymentStatus::Overdue->value)
                ->orWhere(fn (Builder $pending) => $pending
                    ->where('status', PaymentStatus::Pending->value)
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', today())))
        );

        $items = $query()
            ->with('project:id,name')
            ->orderBy('due_date')
            ->limit(self::PREVIEW_LIMIT)
            ->get()
            ->map(fn (Payment $payment): array => [
                'title' => $payment->title.' · '.number_format((float) $payment->amount, 2, '.', ' ').' ₼',
                'meta' => ($payment->project?->name ?? '—')
                    .($payment->due_date ? ' · plan: '.$payment->due_date->format('d.m.Y') : ''),
                'url' => $payment->project_id
                    ? ProjectResource::getUrl('edit', ['record' => $payment->project_id])
                    : null,
                'urgent' => true,
                'badge' => $payment->due_date ? $this->daysLateLabel($payment->due_date) : null,
            ])
            ->all();

        return [
            'key' => 'payments',
            'title' => 'Gecikmiş ödənişlər',
            'subtitle' => 'Plan tarixi keçib, ödəniş daxil olmayıb.',
            'count' => $query()->count(),
            'items' => $items,
            'url' => ProjectResource::getUrl('index'),
            'empty' => 'Gecikmiş ödəniş yoxdur.',
        ];
    }

    /**
     * 5. Cavab gözləyən briflər: `submitted` — dizayner baxmalıdır,
     * `needs_clarification` — top müştəridədir. Hər sətirdə kimin növbəsi
     * olduğu yazılır ki, siyahı «kimin işi» sualına cavab versin.
     */
    private function waitingBriefsBlock(): array
    {
        $query = fn (): Builder => $this->scoped(
            Brief::query()->whereIn('status', [
                BriefStatus::Submitted->value,
                BriefStatus::NeedsClarification->value,
            ])
        );

        $items = $query()
            ->with('project:id,name')
            ->orderBy('submitted_at')
            ->limit(self::PREVIEW_LIMIT)
            ->get()
            ->map(function (Brief $brief): array {
                $submitted = $brief->statusEnum() === BriefStatus::Submitted;

                return [
                    'title' => $brief->project?->name ?? 'Layihə #'.$brief->project_id,
                    'meta' => $brief->statusEnum()->label()
                        .' · '.($submitted ? 'növbə dizaynerdədir' : 'növbə müştəridədir'),
                    'url' => $brief->project_id
                        ? ProjectResource::getUrl('brief-review', ['record' => $brief->project_id])
                        : null,
                    // Dizaynerin öz masasındakı iş daha təcilidir: müştərini
                    // gözlədən tərəf studiyadır.
                    'urgent' => $submitted,
                    'badge' => null,
                ];
            })
            ->all();

        return [
            'key' => 'briefs',
            'title' => 'Cavab gözləyən briflər',
            'subtitle' => 'Təqdim edilmiş və dəqiqləşdirmə gözləyən briflər.',
            'count' => $query()->count(),
            'items' => $items,
            'url' => ProjectResource::getUrl('index'),
            'empty' => 'Cavab gözləyən brif yoxdur.',
        ];
    }

    /**
     * 6. Risk siqnalları — BriefRiskDetector aktiv briflərdə nə tapırsa.
     *
     * PERFORMANS: detektor hər brif üçün cavabları oxuyur və şablon suallarını
     * sorğulayır, yəni qiyməti brif sayına düz mütənasibdir. Ona görə YALNIZ son
     * RISK_PROJECT_LIMIT aktiv layihə üçün hesablanır və cavablar
     * (`brief.answers.question`) bir sorğu ilə əvvəlcədən yüklənir — əks halda
     * bu blok tək başına yüzlərlə sorğu verərdi. Nəticədə `count` da yalnız bu
     * 10 layihəyə aiddir; alt yazıda bu açıq göstərilir ki, rəqəm yanlış
     * oxunmasın.
     */
    private function briefRisksBlock(): array
    {
        $detector = app(BriefRiskDetector::class);

        $projects = $this->scopeProjects(
            Project::query()
                ->where('status', ProjectStatus::Active->value)
                ->whereHas('brief')
                ->with(['brief.answers.question'])
                ->latest('id')
                ->limit(self::RISK_PROJECT_LIMIT)
        )->get();

        $items = [];

        foreach ($projects as $project) {
            if (! $project->brief) {
                continue;
            }

            foreach ($detector->detect($project->brief) as $risk) {
                $items[] = [
                    'title' => $risk['message'],
                    'meta' => $project->name.' · '.$risk['code'],
                    'url' => ProjectResource::getUrl('brief-review', ['record' => $project->getKey()]),
                    'urgent' => $risk['level'] === 'critical',
                    'badge' => $this->riskLevelLabel($risk['level']),
                ];
            }
        }

        return [
            'key' => 'risks',
            'title' => 'Risk siqnalları',
            'subtitle' => 'Son '.self::RISK_PROJECT_LIMIT.' aktiv layihənin brifi üzrə avtomatik yoxlama.',
            'count' => count($items),
            'items' => array_slice($items, 0, self::PREVIEW_LIMIT),
            'url' => ProjectResource::getUrl('index'),
            'empty' => 'Brif risk siqnalı yoxdur.',
        ];
    }

    // ── Köməkçilər ───────────────────────────────────────────────────────────

    /**
     * Layihəyə bağlı modelləri istifadəçinin görə bildiyi layihələrlə
     * məhdudlaşdırır. Studiya izolyasiyası BelongsToTenant qlobal skopundan
     * gəlir; bu isə matrisin «öz layihələri» şərtidir (layihə meneceri, dizayner
     * və s.). Hər iki filtr bir-birini əvəz etmir — biri studiyanı, digəri
     * layihəni kəsir.
     */
    private function scoped(Builder $query): Builder
    {
        $ids = $this->accessibleProjectIds();

        if ($ids !== null) {
            $query->whereIn('project_id', $ids);
        }

        // Layihənin özü də yaşayan və aktual olmalıdır:
        //  — layihə soft-delete olunur, mərhələ/tapşırıq/ödəniş isə yox, yəni
        //    silinmiş layihənin işi burada diri qalırdı. `whereHas` layihənin
        //    qlobal SoftDeletes skopunu işə salır (`stages:mark-overdue` əmri də
        //    eyni qorumadan istifadə edir);
        //  — arxiv statusu «bu layihə ilə daha işləmirik» deməkdir, ona görə onun
        //    gecikmiş işi «bu gün nəyə diqqət lazımdır» sualının cavabı deyil.
        return $query->whereHas('project', fn (Builder $project) => $project
            ->where('status', '!=', ProjectStatus::Archived->value));
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
        // Bir səhifə açılışında 6 blok eyni siyahını soruşur — bir dəfə hesablanır.
        if ($this->visibleProjectIdsResolved) {
            return $this->visibleProjectIds;
        }

        $this->visibleProjectIdsResolved = true;
        $user = auth()->user();

        if ($user === null || ! AccessMatrix::requiresOwnProject($user)) {
            return $this->visibleProjectIds = null;
        }

        $this->visibleProjectIds = Project::query()
            ->where(fn (Builder $q) => $q
                ->where('manager_user_id', $user->id)
                ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->id)))
            ->pluck('id')
            ->all();

        return $this->visibleProjectIds;
    }

    private function daysLateLabel(\DateTimeInterface $date): string
    {
        $days = (int) today()->diffInDays($date, absolute: true);

        return $days.' gün gecikib';
    }

    private function riskLevelLabel(string $level): string
    {
        return match ($level) {
            'critical' => 'Kritik',
            'important' => 'Vacib',
            default => 'Çatışmır',
        };
    }
}
