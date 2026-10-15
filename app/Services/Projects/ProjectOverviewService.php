<?php

namespace App\Services\Projects;

use App\Enums\AccessLevel;
use App\Enums\ApprovalStatus;
use App\Enums\BriefStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\DeliverableStatus;
use App\Enums\Domain;
use App\Enums\ProjectRole;
use App\Enums\PunchIssueStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\ApprovalResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TaskResource;
use App\Models\Approval;
use App\Models\Brief;
use App\Models\ChangeRequest;
use App\Models\ClientUser;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\Project;
use App\Models\ProjectDecision;
use App\Models\PunchListIssue;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessMatrix;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Layihə kartının «İcmal» tabı (TZ «Layihə naviqasiyası» §2) — gündəlik işə
 * başlama ekranı.
 *
 * Burada YENİ məlumat saxlanmır: hər blok mövcud qeydlərin (tapşırıq, irad,
 * material, dəyişiklik sorğusu, brif, razılaşdırma, qərar, activity log)
 * üstündə oxu sorğusudur. Nəticə sadə massivlərdir (DTO) — blade və testlər
 * modellərə toxunmur, lazy loading riski yoxdur.
 *
 * Hüquq qaydası: hər sətir öz mənbəyinin policy-sindən keçir. Bu xidmətin
 * bütün mənbələri layihə əsaslıdır (ScopesProjectDomain: domen səviyyəsi +
 * layihə üzvlüyü) və hamısı EYNİ layihəyə aiddir, ona görə «baxa bilərmi»
 * cavabı sinif üzrə bir dəfə hesablanır (`canView()`); tapşırıq kimi qeydin
 * özündən asılı olan qaydalar sorğu səviyyəsində tətbiq olunur.
 */
class ProjectOverviewService
{
    /** «Yaxınlaşan son tarixlər» pəncərəsi (gün). */
    public const UPCOMING_DAYS = 14;

    /** Bloklarda göstərilən maksimal sətir sayı. */
    private const LIST_LIMIT = 10;

    /** @var array<string, bool> sinif => baxış icazəsi (bir layihə, bir istifadəçi üçün) */
    private array $viewCache = [];

    private ?int $viewCacheKey = null;

    /**
     * Başlıq faktları.
     *
     * @return array{
     *     name: string, client: ?string, address: ?string, type: ?string,
     *     manager: ?string, readiness: int, deadline: ?string, deadline_overdue: bool,
     *     current_stage: ?array{id: int, name: string, status: string, color: string, readiness: int, plan_end: ?string, overdue: bool, responsible: ?string, url: string}
     * }
     */
    public function summary(Project $project): array
    {
        $project->loadMissing(['client', 'manager']);

        $stage = $this->currentStage($project);

        return [
            'name' => $project->name,
            'client' => $project->client?->name,
            'address' => $project->address,
            'type' => $project->type?->label(),
            'manager' => $project->manager?->name,
            'readiness' => (int) $project->readiness,
            'deadline' => $project->deadline?->format('d.m.Y'),
            'deadline_overdue' => $project->deadline !== null && $project->deadline->isPast() && ! $project->deadline->isToday(),
            'current_stage' => $stage ? [
                'id' => $stage->id,
                'name' => $stage->name,
                'status' => $stage->status->label(),
                'color' => $stage->status->color(),
                'readiness' => (int) $stage->readiness,
                'plan_end' => $stage->date_plan_end?->format('d.m.Y'),
                'overdue' => $stage->isOverdue(),
                'responsible' => $stage->responsible?->name,
                'url' => $this->tabUrl($project, 'stages'),
            ] : null,
        ];
    }

    /**
     * Cari mərhələ: işdə/yoxlamada/gecikmiş birinci mərhələ; yoxdursa
     * başlanmamış birinci; hamısı bitibsə sonuncu.
     */
    public function currentStage(Project $project): ?Stage
    {
        $stages = Stage::query()
            ->where('project_id', $project->id)
            ->with('responsible:id,name')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $stages->first(fn (Stage $s) => in_array($s->status, [StageStatus::InProgress, StageStatus::Review, StageStatus::Overdue], true))
            ?? $stages->first(fn (Stage $s) => $s->status === StageStatus::NotStarted)
            ?? $stages->last();
    }

    /**
     * «Sizdən gözlənilən əməliyyatlar» — mövcud qeydlərdən, istifadəçinin
     * məsuliyyəti və hüququna görə. Gecikmişlər öncə, sonra son tarixə görə.
     *
     * @return list<array{key: string, kind: string, action: string, title: string, due: ?string, overdue: bool, url: string}>
     */
    public function pendingActions(Project $project, User $user): array
    {
        $items = collect()
            ->merge($this->taskActions($project, $user))
            ->merge($this->punchActions($project, $user))
            ->merge($this->deliverableActions($project, $user))
            ->merge($this->changeRequestActions($project, $user))
            ->merge($this->briefActions($project, $user))
            ->merge($this->approvalFollowUps($project, $user));

        return $items
            ->sortBy(fn (array $item) => [
                $item['overdue'] ? 0 : 1,
                $item['_sort'] ?? '9999-12-31',
            ])
            ->map(function (array $item): array {
                unset($item['_sort']);

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * Yaxınlaşan (və keçmiş) son tarixlər: mərhələlərin plan bitməsi,
     * tapşırıqların son tarixi, layihənin təhvil tarixi.
     *
     * @return list<array{kind: string, title: string, date: string, overdue: bool, url: string}>
     */
    public function upcomingDeadlines(Project $project, User $user, int $days = self::UPCOMING_DAYS): array
    {
        $horizon = today()->addDays($days);
        $items = collect();

        if (Gate::forUser($user)->allows('viewAny', Stage::class)) {
            Stage::query()
                ->where('project_id', $project->id)
                ->where('status', '!=', StageStatus::Done->value)
                ->whereNotNull('date_plan_end')
                ->whereDate('date_plan_end', '<=', $horizon)
                ->orderBy('date_plan_end')
                ->limit(self::LIST_LIMIT)
                ->get()
                ->filter(fn (Stage $stage) => $this->canView($user, $project, $stage))
                ->each(fn (Stage $stage) => $items->push([
                    'kind' => 'Mərhələ',
                    'title' => $stage->name,
                    'date' => $stage->date_plan_end->format('d.m.Y'),
                    'overdue' => $stage->isOverdue(),
                    'url' => $this->tabUrl($project, 'stages'),
                    '_sort' => $stage->date_plan_end->toDateString(),
                ]));
        }

        if (Gate::forUser($user)->allows('viewAny', Task::class)) {
            TaskResource::scopeToVisibleTasks(Task::query(), $user)
                ->where('project_id', $project->id)
                ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
                ->whereNotNull('deadline')
                ->whereDate('deadline', '<=', $horizon)
                ->orderBy('deadline')
                ->limit(self::LIST_LIMIT)
                ->get()
                ->each(fn (Task $task) => $items->push([
                    'kind' => 'Tapşırıq',
                    'title' => $task->title,
                    'date' => $task->deadline->format('d.m.Y'),
                    'overdue' => $task->isOverdue(),
                    'url' => TaskResource::getUrl('edit', ['record' => $task->getKey()]),
                    '_sort' => $task->deadline->toDateString(),
                ]));
        }

        if ($project->deadline !== null && $project->deadline->lte($horizon)) {
            $items->push([
                'kind' => 'Layihə',
                'title' => 'Təhvil müddəti',
                'date' => $project->deadline->format('d.m.Y'),
                'overdue' => $project->deadline->isPast() && ! $project->deadline->isToday(),
                'url' => ProjectResource::getUrl('settings', ['record' => $project]),
                '_sort' => $project->deadline->toDateString(),
            ]);
        }

        return $items
            ->sortBy('_sort')
            ->take(self::LIST_LIMIT)
            ->map(function (array $item): array {
                unset($item['_sort']);

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * Layihənin iştirakçıları: menecer, komanda üzvləri (layihədəki rolu ilə)
     * və müştəri tərəfi. Müştərinin əlaqə məlumatı yalnız Müştərilər domenini
     * görən rola göstərilir.
     *
     * @return array{
     *     team: list<array{name: string, role: string}>,
     *     client: ?array{name: string, phone: ?string, email: ?string, portal_users: list<string>}
     * }
     */
    public function participants(Project $project, User $user): array
    {
        $project->loadMissing(['manager', 'members', 'client.clientUsers']);

        $team = collect();

        if ($project->manager) {
            $team->put($project->manager->id, [
                'name' => $project->manager->name,
                'role' => 'Məsul menecer',
            ]);
        }

        foreach ($project->members->sortBy('name') as $member) {
            $role = ProjectRole::tryFrom((string) $member->pivot->project_role)?->label()
                ?? (string) $member->pivot->project_role;

            if ($team->has($member->id)) {
                continue;
            }

            $team->put($member->id, ['name' => $member->name, 'role' => $role]);
        }

        $client = null;
        if ($project->client) {
            $seesContacts = Gate::forUser($user)->allows('view', $project->client);

            $client = [
                'name' => $project->client->name,
                'phone' => $seesContacts ? $project->client->phone : null,
                'email' => $seesContacts ? $project->client->email : null,
                'portal_users' => $project->client->clientUsers
                    ->map(fn (ClientUser $cu) => $cu->name)
                    ->filter()
                    ->values()
                    ->all(),
            ];
        }

        return ['team' => $team->values()->all(), 'client' => $client];
    }

    /**
     * «Mühüm hadisələr» lenti: razılaşdırmaya göndərilmə, təsdiq, düzəliş
     * tələbi, qərarlar, dəyişiklik sorğularının status keçidləri və yeni
     * material versiyaları. Mənbəni görə bilməyən istifadəçiyə hadisə çıxmır.
     *
     * @return list<array{at: string, at_label: string, actor: ?string, type: string, text: string, url: ?string}>
     */
    public function feed(Project $project, User $user, int $limit = 20): array
    {
        $events = collect()
            ->merge($this->approvalEvents($project, $user, $limit))
            ->merge($this->decisionEvents($project, $user, $limit))
            ->merge($this->changeRequestEvents($project, $user, $limit))
            ->merge($this->versionEvents($project, $user, $limit));

        return $events
            ->sortByDesc('_ts')
            ->take($limit)
            ->map(function (array $event): array {
                unset($event['_ts']);

                return $event;
            })
            ->values()
            ->all();
    }

    /**
     * Köhnə, obyektə bağlanmamış qeydlər (TZ §10): avtomatik bölüşdürülmür,
     * yoxlama üçün siyahılanır.
     *
     * @return list<array{kind: string, title: string, date: ?string, status: ?string}>
     */
    public function uncheckedRecords(Project $project, User $user): array
    {
        $items = collect();

        ChangeRequest::query()
            ->where('project_id', $project->id)
            ->whereNull('affected_entity_type')
            ->latest('id')
            ->get()
            ->filter(fn (ChangeRequest $cr) => $this->canView($user, $project, $cr))
            ->each(fn (ChangeRequest $cr) => $items->push([
                'kind' => 'Dəyişiklik sorğusu',
                'type' => 'change_request',
                'id' => $cr->id,
                'can_link' => $user->can('update', $cr),
                'title' => trim(($cr->number ? $cr->number.' · ' : '').$cr->title),
                'date' => $cr->created_at?->format('d.m.Y'),
                'status' => $cr->status?->label(),
            ]));

        ProjectDecision::query()
            ->where('project_id', $project->id)
            ->whereNull('related_entity_type')
            ->latest('decided_at')
            ->get()
            ->filter(fn (ProjectDecision $decision) => $this->canView($user, $project, $decision))
            ->each(fn (ProjectDecision $decision) => $items->push([
                'kind' => 'Qərar',
                'type' => 'decision',
                'id' => $decision->id,
                'can_link' => $user->can('update', $decision),
                'title' => $decision->title,
                'date' => ($decision->decided_at ?? $decision->created_at)?->format('d.m.Y'),
                'status' => null,
            ]));

        return $items->values()->all();
    }

    /**
     * Layihə kartının konkret tabına keçid. Açarlar `ProjectResource::getRelations()`
     * ilə eynidir: brief / stages / materials / procurement / supervision.
     *
     * @param  array<string, scalar>  $query
     */
    public function tabUrl(Project $project, ?string $tab, array $query = []): string
    {
        $url = ProjectResource::getUrl('edit', ['record' => $project]);
        $params = array_filter(['relation' => $tab, ...$query], fn ($v) => $v !== null && $v !== '');

        return $params === [] ? $url : $url.'?'.http_build_query($params);
    }

    /**
     * Polimorf obyektin (morph alias) yerləşdiyi tab.
     */
    public function tabForEntity(?string $entityType): ?string
    {
        return match ($entityType) {
            'deliverable', 'deliverable_version', 'document', 'project_file' => 'materials',
            'stage', 'task' => 'stages',
            'procurement_item', 'specification_item' => 'procurement',
            'punch_list_issue', 'diary_entry' => 'supervision',
            'brief' => 'brief',
            default => null,
        };
    }

    // ── «Sizdən gözlənilən» mənbələri ────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function taskActions(Project $project, User $user): array
    {
        if (! Gate::forUser($user)->allows('viewAny', Task::class)) {
            return [];
        }

        $base = fn (): Builder => TaskResource::scopeToVisibleTasks(Task::query(), $user)
            ->where('project_id', $project->id);

        $assigned = $base()
            ->where('assignee_user_id', $user->id)
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->orderByRaw('deadline is null')
            ->orderBy('deadline')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Task $task) => [
                'key' => 'task-'.$task->id,
                'kind' => 'Tapşırıq',
                'action' => 'Tapşırığı yerinə yetirin',
                'title' => $task->title,
                ...$this->due($task->deadline),
                'overdue' => $task->isOverdue(),
                'url' => TaskResource::getUrl('edit', ['record' => $task->getKey()]),
            ]);

        // Yoxlamaya verilmiş tapşırıq onu yaradanı gözləyir.
        $review = $base()
            ->where('author_user_id', $user->id)
            ->where(fn (Builder $q) => $q->whereNull('assignee_user_id')->orWhere('assignee_user_id', '!=', $user->id))
            ->where('status', TaskStatus::Review->value)
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Task $task) => [
                'key' => 'task-review-'.$task->id,
                'kind' => 'Tapşırıq',
                'action' => 'Görülmüş işi yoxlayın',
                'title' => $task->title,
                ...$this->due($task->deadline),
                'overdue' => $task->isOverdue(),
                'url' => TaskResource::getUrl('edit', ['record' => $task->getKey()]),
            ]);

        return $assigned->merge($review)->all();
    }

    /** @return list<array<string, mixed>> */
    private function punchActions(Project $project, User $user): array
    {
        $gate = Gate::forUser($user);

        if (! $gate->allows('viewAny', PunchListIssue::class)) {
            return [];
        }

        $mine = PunchListIssue::query()
            ->where('project_id', $project->id)
            ->where('responsible_user_id', $user->id)
            ->whereIn('status', [
                PunchIssueStatus::Open->value,
                PunchIssueStatus::Assigned->value,
                PunchIssueStatus::InProgress->value,
                PunchIssueStatus::Rejected->value,
            ])
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->limit(self::LIST_LIMIT)
            ->get();

        // Düzəliş görülüb, layihə meneceri (və ya layihədə Tam səviyyəli rol) yoxlamalıdır.
        $toReview = collect();
        if ($this->managesProject($project, $user)) {
            $toReview = PunchListIssue::query()
                ->where('project_id', $project->id)
                ->where('status', PunchIssueStatus::ReadyForReview->value)
                ->orderByRaw('due_date is null')
                ->orderBy('due_date')
                ->limit(self::LIST_LIMIT)
                ->get();
        }

        $url = $this->tabUrl($project, 'supervision');

        return $mine
            ->filter(fn (PunchListIssue $issue) => $this->canView($user, $project, $issue))
            ->map(fn (PunchListIssue $issue) => [
                'key' => 'punch-'.$issue->id,
                'kind' => 'İrad',
                'action' => 'İradı aradan qaldırın',
                'title' => $issue->title,
                ...$this->due($issue->due_date),
                'overdue' => $this->isPast($issue->due_date),
                'url' => $url,
            ])
            ->merge($toReview
                ->filter(fn (PunchListIssue $issue) => $this->canView($user, $project, $issue))
                ->map(fn (PunchListIssue $issue) => [
                    'key' => 'punch-review-'.$issue->id,
                    'kind' => 'İrad',
                    'action' => 'Düzəlişi yoxlayın və iradı bağlayın',
                    'title' => $issue->title,
                    ...$this->due($issue->due_date),
                    'overdue' => false,
                    'url' => $url,
                ]))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function deliverableActions(Project $project, User $user): array
    {
        if (! Gate::forUser($user)->allows('viewAny', Deliverable::class)) {
            return [];
        }

        return Deliverable::query()
            ->where('project_id', $project->id)
            ->where('responsible_user_id', $user->id)
            ->where('status', DeliverableStatus::RevisionRequired->value)
            ->latest('updated_at')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->filter(fn (Deliverable $deliverable) => $this->canView($user, $project, $deliverable))
            ->map(fn (Deliverable $deliverable) => [
                'key' => 'deliverable-'.$deliverable->id,
                'kind' => 'Material',
                'action' => 'Düzəliş tələb olunur — yeni versiya hazırlayın',
                'title' => $deliverable->title,
                ...$this->due(null),
                'overdue' => false,
                'url' => $this->tabUrl($project, 'materials', ['stage' => $deliverable->stage_id]),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function changeRequestActions(Project $project, User $user): array
    {
        $gate = Gate::forUser($user);

        $requests = ChangeRequest::query()
            ->where('project_id', $project->id)
            ->where('status', ChangeRequestStatus::WaitingInternalApproval->value)
            ->latest('updated_at')
            ->limit(self::LIST_LIMIT)
            ->get();

        return $requests
            ->filter(function (ChangeRequest $cr) use ($gate, $project): bool {
                $cr->setRelation('project', $project);

                return $gate->allows('approve', $cr);
            })
            ->map(fn (ChangeRequest $cr) => [
                'key' => 'change-request-'.$cr->id,
                'kind' => 'Dəyişiklik sorğusu',
                'action' => 'Studiya adından təsdiqləyin və ya rədd edin',
                'title' => trim(($cr->number ? $cr->number.' · ' : '').$cr->title),
                ...$this->due(null),
                'overdue' => false,
                'url' => $this->entityUrl($project, $cr->affected_entity_type, $cr->affected_entity_id),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function briefActions(Project $project, User $user): array
    {
        // Brifi təsdiqləmək / dəqiqləşdirmə istəmək — `BriefReview::canManageBrief()` səddi.
        if (! AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full)
            || ! Gate::forUser($user)->allows('view', $project)) {
            return [];
        }

        return Brief::query()
            ->where('project_id', $project->id)
            ->where('status', BriefStatus::Submitted->value)
            ->with('template')
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (Brief $brief) => [
                'key' => 'brief-'.$brief->id,
                'kind' => 'Brif',
                'action' => 'Müştəri brifi təqdim edib — baxın və təsdiqləyin',
                'title' => $brief->template?->getTranslation('name', app()->getLocale()) ?: 'Brif',
                ...$this->due(null),
                'overdue' => false,
                'url' => ProjectResource::getUrl('brief-review', ['record' => $project, 'brief' => $brief->getKey()]),
            ])
            ->values()
            ->all();
    }

    /**
     * Müştəridə cavab müddəti keçmiş razılaşdırmalar — menecer (və sorğunu
     * göndərən) üçün «müştəri ilə əlaqə saxla» xatırlatması.
     *
     * @return list<array<string, mixed>>
     */
    private function approvalFollowUps(Project $project, User $user): array
    {
        if (! Gate::forUser($user)->allows('viewAny', Approval::class)) {
            return [];
        }

        $isManager = (int) $project->manager_user_id === (int) $user->id;

        return Approval::query()
            ->where('project_id', $project->id)
            ->where('status', ApprovalStatus::Pending->value)
            ->whereNotNull('respond_by')
            ->whereDate('respond_by', '<', today())
            ->when(! $isManager, fn (Builder $q) => $q->where('requested_by_user_id', $user->id))
            ->with('approvable')
            ->orderBy('respond_by')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->filter(fn (Approval $approval) => $this->canView($user, $project, $approval))
            ->map(fn (Approval $approval) => [
                'key' => 'approval-'.$approval->id,
                'kind' => 'Razılaşdırma',
                'action' => 'Müştərinin cavab müddəti keçib — əlaqə saxlayın',
                'title' => $approval->subjectLabel(),
                ...$this->due($approval->respond_by),
                'overdue' => true,
                'url' => $this->approvalUrl($project, $approval),
            ])
            ->values()
            ->all();
    }

    // ── Lent mənbələri ───────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function approvalEvents(Project $project, User $user, int $limit): array
    {
        if (! Gate::forUser($user)->allows('viewAny', Approval::class)) {
            return [];
        }

        $approvals = Approval::query()
            ->where('project_id', $project->id)
            ->with(['approvable', 'requestedBy:id,name', 'clientUser:id,name'])
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->filter(fn (Approval $approval) => $this->canView($user, $project, $approval));

        $events = [];

        foreach ($approvals as $approval) {
            $label = $approval->subjectLabel();
            $url = $this->approvalUrl($project, $approval);
            $version = $approval->version ? ' (v'.$approval->version.')' : '';

            $events[] = $this->event(
                $approval->created_at,
                $approval->requestedBy?->name,
                'sent',
                'Razılaşdırmaya göndərildi: '.$label.$version,
                $url,
            );

            if ($approval->decided_at !== null && in_array($approval->status, [ApprovalStatus::Approved, ApprovalStatus::Rejected], true)) {
                $events[] = $this->event(
                    $approval->decided_at,
                    $approval->clientUser?->name ?? 'Müştəri',
                    $approval->status === ApprovalStatus::Approved ? 'approved' : 'revision',
                    ($approval->status === ApprovalStatus::Approved ? 'Təsdiqləndi: ' : 'Düzəliş tələb olundu: ').$label.$version,
                    $url,
                );
            }
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    private function decisionEvents(Project $project, User $user, int $limit): array
    {
        if (! Gate::forUser($user)->allows('viewAny', ProjectDecision::class)) {
            return [];
        }

        return ProjectDecision::query()
            ->where('project_id', $project->id)
            ->with('madeBy:id,name')
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->filter(fn (ProjectDecision $decision) => $this->canView($user, $project, $decision))
            ->map(fn (ProjectDecision $decision) => $this->event(
                $decision->decided_at ?? $decision->created_at,
                $decision->madeBy?->name,
                'decision',
                'Qərar qeydə alındı: '.$decision->title,
                $this->entityUrl($project, $decision->related_entity_type, $decision->related_entity_id),
            ))
            ->values()
            ->all();
    }

    /**
     * Dəyişiklik sorğusunun status keçidləri — activity log-dan (ChangeRequest
     * `status`-u loglayır). Yaradılma da hadisədir.
     *
     * @return list<array<string, mixed>>
     */
    private function changeRequestEvents(Project $project, User $user, int $limit): array
    {
        if (! Gate::forUser($user)->allows('viewAny', ChangeRequest::class)) {
            return [];
        }

        $requests = ChangeRequest::query()
            ->where('project_id', $project->id)
            ->get()
            ->filter(fn (ChangeRequest $cr) => $this->canView($user, $project, $cr))
            ->keyBy('id');

        if ($requests->isEmpty()) {
            return [];
        }

        return Activity::query()
            ->where('subject_type', (new ChangeRequest)->getMorphClass())
            ->whereIn('subject_id', $requests->keys())
            ->whereIn('event', ['created', 'updated'])
            ->with('causer')
            ->latest('id')
            ->limit($limit * 3)
            ->get()
            ->map(function (Activity $activity) use ($requests, $project): ?array {
                $status = data_get($activity->properties, 'attributes.status');
                $old = data_get($activity->properties, 'old.status');

                if ($activity->event === 'updated' && ($status === null || $status === $old)) {
                    return null;
                }

                /** @var ChangeRequest $cr */
                $cr = $requests->get($activity->subject_id);
                $name = trim(($cr->number ? $cr->number.' · ' : '').$cr->title);
                $label = ChangeRequestStatus::tryFrom((string) $status)?->label() ?? (string) $status;

                return $this->event(
                    $activity->created_at,
                    $this->actorName($activity->causer),
                    'change_request',
                    $activity->event === 'created'
                        ? 'Dəyişiklik sorğusu açıldı: '.$name
                        : 'Dəyişiklik sorğusu «'.$label.'»: '.$name,
                    $this->entityUrl($project, $cr->affected_entity_type, $cr->affected_entity_id),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function versionEvents(Project $project, User $user, int $limit): array
    {
        if (! Gate::forUser($user)->allows('viewAny', DeliverableVersion::class)) {
            return [];
        }

        return DeliverableVersion::query()
            ->whereHas('deliverable', fn (Builder $q) => $q->where('project_id', $project->id))
            ->with(['deliverable:id,project_id,stage_id,title', 'createdBy:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->filter(function (DeliverableVersion $version) use ($user, $project): bool {
                $version->deliverable?->setRelation('project', $project);

                return $version->deliverable !== null && $this->canView($user, $project, $version);
            })
            ->map(fn (DeliverableVersion $version) => $this->event(
                $version->created_at,
                $version->createdBy?->name,
                'version',
                'Yeni versiya: '.$version->deliverable->title.' (v'.$version->version_number.')',
                $this->tabUrl($project, 'materials', ['stage' => $version->deliverable->stage_id]),
            ))
            ->values()
            ->all();
    }

    // ── Köməkçilər ───────────────────────────────────────────────────────────

    /**
     * Qeydə baxış icazəsi, sinif üzrə bir dəfə hesablanır. Etibarlıdır, çünki
     * bu xidmətin bütün mənbələrinin `view` qaydası yalnız istifadəçidən və
     * qeydin LAYİHƏSİNDƏN asılıdır (ScopesProjectDomain), layihə isə hamısında
     * eynidir. Qeydin özündən asılı qaydalar (tapşırıq) sorğuda tətbiq olunur.
     */
    private function canView(User $user, Project $project, Model $record): bool
    {
        $key = $user->id * 1_000_000 + $project->id;
        if ($this->viewCacheKey !== $key) {
            $this->viewCache = [];
            $this->viewCacheKey = $key;
        }

        $class = $record::class;

        if (! array_key_exists($class, $this->viewCache)) {
            if (! $record instanceof DeliverableVersion) {
                $record->setRelation('project', $project);
            }

            $this->viewCache[$class] = Gate::forUser($user)->allows('view', $record);
        }

        return $this->viewCache[$class];
    }

    /** Layihədə Tam səviyyəli idarəetmə: menecer, sahibkar. */
    private function managesProject(Project $project, User $user): bool
    {
        return (int) $project->manager_user_id === (int) $user->id
            || Gate::forUser($user)->allows('delete', $project);
    }

    private function approvalUrl(Project $project, Approval $approval): string
    {
        $query = [];
        if ($approval->approvable instanceof Deliverable) {
            $query['stage'] = $approval->approvable->stage_id;
        }

        $tab = $this->tabForEntity($approval->approvable_type);

        // Smeta/sənəd kimi layihə tabı olmayan obyektlər — ümumi razılaşdırma siyahısı.
        return $tab ? $this->tabUrl($project, $tab, $query) : ApprovalResource::getUrl('index');
    }

    private function entityUrl(Project $project, ?string $type, mixed $id): string
    {
        $tab = $this->tabForEntity($type);

        // Obyektə bağlanmamış köhnə qeyd — İcmaldakı «Yoxlanmalı qeydlər» bloku.
        return $tab ? $this->tabUrl($project, $tab) : $this->tabUrl($project, null).'#yoxlanmali-qeydler';
    }

    private function actorName(?Model $causer): ?string
    {
        return match (true) {
            $causer instanceof User => $causer->name,
            $causer instanceof ClientUser => $causer->name.' (müştəri)',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function event(?CarbonInterface $at, ?string $actor, string $type, string $text, ?string $url): array
    {
        $at ??= now();

        return [
            'at' => $at->toIso8601String(),
            'at_label' => $at->format('d.m.Y H:i'),
            'actor' => $actor,
            'type' => $type,
            'text' => $text,
            'url' => $url,
            '_ts' => $at->getTimestamp(),
        ];
    }

    /** @return array{due: ?string, _sort: ?string} */
    private function due(?CarbonInterface $date): array
    {
        return [
            'due' => $date?->format('d.m.Y'),
            '_sort' => $date?->toDateString(),
        ];
    }

    private function isPast(?CarbonInterface $date): bool
    {
        return $date !== null && $date->isPast() && ! $date->isToday();
    }
}
