<?php

namespace App\Services\Portal;

use App\Enums\ApprovalStatus;
use App\Enums\BriefStatus;
use App\Enums\ProjectRole;
use App\Enums\StageStatus;
use App\Models\Approval;
use App\Models\Brief;
use App\Models\BudgetLine;
use App\Models\ChangeRequest;
use App\Models\ClientUser;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\Document;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\Stage;
use App\Models\User;
use App\Services\Projects\ProjectOverviewService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Portalın «İcmal» bölməsi (TZ «Layihə naviqasiyası» §1–2, qəbul 14, 17,
 * ssenari 2) — müştərinin gündəlik başlanğıc ekranı.
 *
 * Heyətin {@see ProjectOverviewService}-i ilə eyni
 * quruluşdur, amma mənbələr MÜŞTƏRİYƏ açıq olanlarla məhdudlaşır:
 *  - gözlənilən əməliyyatlar = bu layihədə müştəriyə göndərilmiş cavabsız
 *    razılaşdırmalar + doldurulmalı / dəqiqləşdirilməli briflər;
 *  - lent = razılaşdırmaya göndərilmə və qərarlar, materialın DƏRC edilməsi,
 *    mərhələ statusunun dəyişməsi.
 *
 * Lentə QƏSDƏN düşməyənlər: daxili qərarlar (`project_decisions`), şərhlər,
 * dəyişiklik sorğularının daxili vəziyyətləri, dərc edilməmiş versiyalar,
 * iradlar. Geri çəkilmiş (`draft`) razılaşdırmalar da göstərilmir.
 *
 * Layihə həmişə çağıranın `ResolvesClientProjects` ilə həll etdiyi layihədir —
 * xidmət özü heç bir layihəni id ilə axtarmır. Nəticə sadə massivlərdir.
 */
class ClientOverviewService
{
    /** «Yaxınlaşan son tarixlər» pəncərəsi (gün). */
    public const UPCOMING_DAYS = 14;

    private const LIST_LIMIT = 10;

    /** Müştəriyə göndərilmiş (portalda görünən) razılaşdırma statusları. */
    private const CLIENT_APPROVAL_STATUSES = [
        ApprovalStatus::Pending,
        ApprovalStatus::Approved,
        ApprovalStatus::Rejected,
    ];

    /**
     * Cari mərhələ: işdə / yoxlamada / gecikmiş birinci; yoxdursa başlanmamış
     * birinci; hamısı bitibsə sonuncu (heyətin icmalı ilə eyni qayda).
     */
    public function currentStage(Project $project): ?Stage
    {
        $stages = $this->stages($project);

        return $stages->first(fn (Stage $s) => in_array($s->status, [StageStatus::InProgress, StageStatus::Review, StageStatus::Overdue], true))
            ?? $stages->first(fn (Stage $s) => $s->status === StageStatus::NotStarted)
            ?? $stages->last();
    }

    /**
     * «Sizdən gözlənilən əməliyyatlar». Gecikmişlər öncə, sonra cavab müddətinə
     * görə; müddətsizlər sonda.
     *
     * @return list<array{key: string, kind: string, action: string, title: string, due: ?string, overdue: bool, url: string}>
     */
    public function pendingActions(Project $project): array
    {
        return collect()
            ->merge($this->approvalActions($project))
            ->merge($this->briefActions($project))
            ->sortBy(fn (array $item) => [$item['overdue'] ? 0 : 1, $item['_sort'] ?? '9999-12-31'])
            ->map(fn (array $item) => collect($item)->except('_sort')->all())
            ->values()
            ->all();
    }

    /**
     * Tamamlanmamış mərhələlərin plan bitməsi və layihənin təhvil müddəti —
     * pəncərə daxilində və ya artıq keçmiş.
     *
     * @return list<array{kind: string, title: string, date: string, overdue: bool, url: ?string}>
     */
    public function upcomingDeadlines(Project $project, int $days = self::UPCOMING_DAYS): array
    {
        $horizon = today()->addDays($days);

        $items = $this->stages($project)
            ->filter(fn (Stage $stage) => $stage->status !== StageStatus::Done
                && $stage->date_plan_end !== null
                && $stage->date_plan_end->lte($horizon))
            ->map(fn (Stage $stage) => [
                'kind' => t('portal.overview_kind_stage'),
                'title' => $stage->name,
                'date' => $stage->date_plan_end->format('d.m.Y'),
                'overdue' => $stage->isOverdue(),
                'url' => route('portal.stages', $project).'#stage-'.$stage->id,
                '_sort' => $stage->date_plan_end->toDateString(),
            ]);

        if ($project->deadline !== null && $project->deadline->lte($horizon)) {
            $items->push([
                'kind' => t('portal.overview_kind_project'),
                'title' => t('portal.deadline'),
                'date' => $project->deadline->format('d.m.Y'),
                'overdue' => $this->isPast($project->deadline),
                'url' => null,
                '_sort' => $project->deadline->toDateString(),
            ]);
        }

        return $items
            ->sortBy('_sort')
            ->take(self::LIST_LIMIT)
            ->map(fn (array $item) => collect($item)->except('_sort')->all())
            ->values()
            ->all();
    }

    /**
     * Müştərinin görə biləcəyi iştirakçılar: məsul menecer və layihə
     * komandası (adı və layihədəki rolu). Əlaqə məlumatı verilmir.
     *
     * @return list<array{name: string, role: string}>
     */
    public function participants(Project $project): array
    {
        $project->loadMissing(['manager:id,name', 'members:id,name']);

        $team = collect();

        if ($project->manager) {
            $team->put($project->manager->id, ['name' => $project->manager->name, 'role' => t('portal.manager')]);
        }

        foreach ($project->members->sortBy('name') as $member) {
            if ($team->has($member->id)) {
                continue;
            }

            $team->put($member->id, ['name' => $member->name, 'role' => $this->roleLabel((string) $member->pivot->project_role)]);
        }

        return $team->values()->all();
    }

    /**
     * Müştəri üçün təhlükəsiz hadisə lenti.
     *
     * @return list<array{at_label: string, actor: ?string, type: string, text: string, url: ?string}>
     */
    public function feed(Project $project, int $limit = 20): array
    {
        return collect()
            ->merge($this->approvalEvents($project, $limit))
            ->merge($this->publicationEvents($project, $limit))
            ->merge($this->stageEvents($project, $limit))
            ->sortByDesc('_ts')
            ->take($limit)
            ->map(fn (array $event) => collect($event)->except('_ts')->all())
            ->values()
            ->all();
    }

    /**
     * Razılaşdırmanın müştəri üçün birbaşa keçidi: material → «Fayllar»dakı
     * kartı (qərar orada verilir), qalanları → razılaşdırma kartı.
     */
    public function approvalUrl(Project $project, Approval $approval): string
    {
        if ($approval->approvable instanceof Deliverable && $approval->approvable->isPublished()) {
            return route('portal.files', $project).'#material-'.$approval->approvable->id;
        }

        return route('portal.approvals', $project).'#approval-'.$approval->id;
    }

    /** Razılaşdırılan obyektin müştəriyə göstərilən adı (versiya ilə). */
    public function subjectTitle(Approval $approval): string
    {
        $subject = $approval->approvable;

        return match (true) {
            $subject instanceof Deliverable => $subject->title.($approval->deliverableVersion ? ' (v'.$approval->deliverableVersion->version_number.')' : ''),
            $subject instanceof ProcurementItem => $subject->name,
            $subject instanceof BudgetLine => (string) $subject->work_type,
            $subject instanceof Stage => $subject->name,
            $subject instanceof Document => $subject->title,
            $subject instanceof ChangeRequest => trim(($subject->number ? $subject->number.' · ' : '').$subject->title),
            default => $approval->subjectLabel(),
        };
    }

    // ── Gözlənilən əməliyyatlar ─────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function approvalActions(Project $project): array
    {
        return $project->approvals()
            ->where('status', ApprovalStatus::Pending->value)
            ->with(['approvable', 'deliverableVersion:id,deliverable_id,version_number'])
            ->latest('id')
            ->get()
            // Sorğudan sonra silinmiş obyektin qərarı verilə bilməz — siyahıda qalmır.
            ->filter(fn (Approval $approval) => $approval->approvable !== null)
            ->map(fn (Approval $approval) => [
                'key' => 'approval-'.$approval->id,
                'kind' => $this->approvalKind($approval),
                'action' => $this->approvalAction($approval),
                'title' => $this->subjectTitle($approval),
                'due' => $approval->respond_by?->format('d.m.Y'),
                'overdue' => $approval->isOverdue(),
                'url' => $this->approvalUrl($project, $approval),
                '_sort' => $approval->respond_by?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function briefActions(Project $project): array
    {
        $locale = app()->getLocale();

        return Brief::query()
            ->where('project_id', $project->id)
            ->whereNotNull('presented_at')
            ->whereIn('status', [
                BriefStatus::Draft->value, BriefStatus::Sent->value,
                BriefStatus::InProgress->value, BriefStatus::NeedsClarification->value,
            ])
            ->with('template')
            ->orderBy('presented_at')
            ->get()
            ->map(function (Brief $brief) use ($project, $locale): array {
                $clarify = $brief->needsClarification();

                return [
                    'key' => 'brief-'.$brief->id,
                    'kind' => t('portal.nav_brief'),
                    'action' => $clarify ? t('portal.action_answer_clarifications') : t('portal.action_fill_brief'),
                    'title' => $brief->template?->getTranslation('name', $locale, true) ?: t('portal.nav_brief'),
                    'due' => null,
                    'overdue' => false,
                    'url' => $clarify
                        ? route('portal.brief.clarifications', [$project, 'brief' => $brief->id])
                        : route('portal.brief', [$project, 'brief' => $brief->id]),
                    '_sort' => null,
                ];
            })
            ->values()
            ->all();
    }

    private function approvalKind(Approval $approval): string
    {
        return match (true) {
            $approval->approvable instanceof Deliverable => t('portal.overview_kind_material'),
            $approval->approvable instanceof ProcurementItem => t('portal.nav_procurement'),
            $approval->approvable instanceof BudgetLine => t('portal.nav_estimate'),
            $approval->approvable instanceof Stage => t('portal.overview_kind_stage'),
            $approval->approvable instanceof ChangeRequest => t('portal.overview_kind_change'),
            default => t('portal.overview_kind_document'),
        };
    }

    private function approvalAction(Approval $approval): string
    {
        if ($approval->hasVariants()) {
            return t('portal.action_choose_variant');
        }

        return match (true) {
            $approval->approvable instanceof Deliverable => t('portal.action_approve_material'),
            $approval->approvable instanceof ChangeRequest => t('portal.action_approve_change'),
            default => t('portal.action_approve'),
        };
    }

    // ── Lent ────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function approvalEvents(Project $project, int $limit): array
    {
        $approvals = $project->approvals()
            ->whereIn('status', array_map(fn (ApprovalStatus $s) => $s->value, self::CLIENT_APPROVAL_STATUSES))
            ->with(['approvable', 'deliverableVersion:id,deliverable_id,version_number', 'requestedBy:id,name', 'clientUser:id,name'])
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->filter(fn (Approval $approval) => $approval->approvable !== null);

        $events = [];

        foreach ($approvals as $approval) {
            $subject = $this->subjectTitle($approval);
            $url = $this->approvalUrl($project, $approval);

            $events[] = $this->event($approval->created_at, $approval->requestedBy?->name, 'sent',
                t('portal.feed_sent', ['subject' => $subject]), $url);

            if ($approval->decided_at !== null && $approval->status !== ApprovalStatus::Pending) {
                $approved = $approval->status === ApprovalStatus::Approved;

                $events[] = $this->event(
                    $approval->decided_at,
                    $approval->clientUser?->name,
                    $approved ? 'approved' : 'revision',
                    t($approved ? 'portal.feed_approved' : 'portal.feed_revision', ['subject' => $subject]),
                    $url,
                );
            }
        }

        return $events;
    }

    /**
     * Materialın dərc edilməsi — activity log-dan (`published_version_id`).
     * Yalnız HAZIRDA dərc edilmiş materiallar və müştəriyə açıq versiyalar:
     * dərcdən çıxarılmış materialın adı lentdə də qalmır.
     *
     * @return list<array<string, mixed>>
     */
    private function publicationEvents(Project $project, int $limit): array
    {
        /** @var Collection<int, Deliverable> $materials */
        $materials = $project->deliverables()
            ->whereNotNull('published_version_id')
            ->with('approvals:id,approvable_type,approvable_id,deliverable_version_id,status')
            ->get(['id', 'project_id', 'title', 'published_version_id'])
            ->keyBy('id');

        if ($materials->isEmpty()) {
            return [];
        }

        $activities = Activity::query()
            ->where('subject_type', (new Deliverable)->getMorphClass())
            ->whereIn('subject_id', $materials->keys())
            ->whereIn('event', ['created', 'updated'])
            ->whereNotNull('properties->attributes->published_version_id')
            ->with('causer')
            ->latest('id')
            ->limit($limit * 2)
            ->get();

        $versionNumbers = DeliverableVersion::query()
            ->whereIn('id', $activities->map(fn (Activity $a) => (int) data_get($a->properties, 'attributes.published_version_id'))->unique())
            ->pluck('version_number', 'id');

        return $activities
            ->map(function (Activity $activity) use ($materials, $versionNumbers, $project): ?array {
                $versionId = (int) data_get($activity->properties, 'attributes.published_version_id');

                if ($versionId === 0 || $versionId === (int) data_get($activity->properties, 'old.published_version_id')) {
                    return null;
                }

                /** @var Deliverable $material */
                $material = $materials->get($activity->subject_id);

                if (! in_array($versionId, $this->clientVersionIds($material), true) || ! $versionNumbers->has($versionId)) {
                    return null;
                }

                return $this->event(
                    $activity->created_at,
                    $this->actorName($activity->causer),
                    'published',
                    t('portal.feed_published', ['subject' => $material->title.' (v'.$versionNumbers->get($versionId).')']),
                    route('portal.files', $project).'#material-'.$material->id,
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function stageEvents(Project $project, int $limit): array
    {
        $stages = $this->stages($project)->keyBy('id');

        if ($stages->isEmpty()) {
            return [];
        }

        return Activity::query()
            ->where('subject_type', (new Stage)->getMorphClass())
            ->whereIn('subject_id', $stages->keys())
            ->where('event', 'updated')
            ->whereNotNull('properties->attributes->status')
            ->with('causer')
            ->latest('id')
            ->limit($limit * 2)
            ->get()
            ->map(function (Activity $activity) use ($stages, $project): ?array {
                $status = data_get($activity->properties, 'attributes.status');

                if ($status === null || $status === data_get($activity->properties, 'old.status')) {
                    return null;
                }

                $stage = $stages->get($activity->subject_id);
                $label = StageStatus::tryFrom((string) $status)?->translatedLabel() ?? (string) $status;

                return $this->event(
                    $activity->created_at,
                    $this->actorName($activity->causer),
                    'stage',
                    t('portal.feed_stage_status', ['stage' => $stage->name, 'status' => $label]),
                    route('portal.stages', $project).'#stage-'.$stage->id,
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    // ── Köməkçilər ───────────────────────────────────────────────────────────

    /** @return Collection<int, Stage> */
    private function stages(Project $project): Collection
    {
        if (! $project->relationLoaded('stages')) {
            $project->load(['stages' => fn ($query) => $query->orderBy('position')->orderBy('id')]);
        }

        return $project->stages;
    }

    /**
     * {@see Deliverable::clientVersionIds()} əvvəlcədən yüklənmiş
     * razılaşdırmalarla (hər material üçün ayrıca sorğu getməsin).
     *
     * @return list<int>
     */
    private function clientVersionIds(Deliverable $material): array
    {
        $sent = $material->approvals
            ->filter(fn (Approval $approval) => in_array($approval->status, self::CLIENT_APPROVAL_STATUSES, true))
            ->pluck('deliverable_version_id')
            ->filter()
            ->map(fn ($id) => (int) $id);

        return $sent->push((int) $material->published_version_id)->unique()->values()->all();
    }

    private function roleLabel(string $role): string
    {
        $key = 'portal.role_'.$role;
        $translated = t($key);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        return ProjectRole::tryFrom($role)?->label() ?? $role;
    }

    private function actorName(mixed $causer): ?string
    {
        return ($causer instanceof User || $causer instanceof ClientUser) ? $causer->name : null;
    }

    /** @return array<string, mixed> */
    private function event(?CarbonInterface $at, ?string $actor, string $type, string $text, ?string $url): array
    {
        $at ??= now();

        return [
            'at_label' => $at->format('d.m.Y H:i'),
            'actor' => $actor,
            'type' => $type,
            'text' => $text,
            'url' => $url,
            '_ts' => $at->getTimestamp(),
        ];
    }

    private function isPast(?CarbonInterface $date): bool
    {
        return $date !== null && $date->isPast() && ! $date->isToday();
    }
}
