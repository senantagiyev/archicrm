<?php

namespace App\Services\Portal;

use App\Enums\ApprovalStatus;
use App\Enums\DeliverableType;
use App\Enums\MaterialApprovalState;
use App\Models\Approval;
use App\Models\Comment;
use App\Models\Deliverable;
use App\Models\Document;
use App\Models\Project;
use App\Models\Stage;
use Illuminate\Support\Collection;

/**
 * Portalın «Fayllar» bölməsindəki material kartları (TZ «Layihə naviqasiyası»
 * §5–6, qəbul 7, 12, 15, ssenari 2).
 *
 * Kart YALNIZ dərc edilmiş versiyanı göstərir — daha yeni daxili versiya,
 * daxili şərhlər və geri çəkilmiş (`draft`) razılaşdırmalar kartda yoxdur.
 * Müştərinin cavab gözləyən razılaşdırması varsa, «Təsdiqlə» / «Düzəliş tələb
 * et» elə kartdadır (qərarı mövcud `portal.approvals.decide` yazır).
 *
 * Bütün əlaqələr bir dəfəyə yüklənir — kart sayından asılı sorğu yoxdur.
 */
class ClientMaterialCards
{
    /** Müştəriyə göndərilmiş (portalda görünən) razılaşdırma statusları. */
    private const CLIENT_APPROVAL_STATUSES = [
        ApprovalStatus::Pending,
        ApprovalStatus::Approved,
        ApprovalStatus::Rejected,
    ];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function cards(Project $project, ?Stage $stage = null, ?DeliverableType $category = null): Collection
    {
        $clientUserId = auth('customer')->id();

        $materials = $project->deliverables()
            ->whereNotNull('published_version_id')
            ->when($stage, fn ($query) => $query->where('stage_id', $stage->id))
            ->when($category, fn ($query) => $query->where('type', $category->value))
            ->with([
                'stage:id,name',
                'publishedVersion.comments' => fn ($query) => $query->forClient()->with('author'),
                'approvals' => fn ($query) => $query
                    ->whereIn('status', array_map(fn (ApprovalStatus $s) => $s->value, self::CLIENT_APPROVAL_STATUSES))
                    ->with(['clientUser:id,name', 'deliverableVersion:id,deliverable_id,version_number'])
                    ->orderByDesc('id'),
                'legacyDocuments:id,migrated_deliverable_id',
            ])
            ->get()
            ->filter(fn (Deliverable $material) => $material->publishedVersion !== null);

        $legacyApprovals = $this->legacyDocumentApprovals($materials);

        return $materials
            ->map(fn (Deliverable $material) => $this->card($project, $material, $legacyApprovals->get($material->id, collect()), $clientUserId))
            ->values();
    }

    /** Kateqoriyanın müştəri dilində adı (tərcümə yoxdursa — standart ad). */
    public static function categoryLabel(?DeliverableType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $key = 'portal.material_category_'.$type->value;
        $translated = t($key);

        return is_string($translated) && $translated !== $key ? $translated : $type->label();
    }

    /**
     * @param  Collection<int, Approval>  $legacyApprovals
     * @return array<string, mixed>
     */
    private function card(Project $project, Deliverable $material, Collection $legacyApprovals, int|string|null $clientUserId): array
    {
        $version = $material->publishedVersion;

        $files = collect($version->files())->values()->map(fn (array $file, int $index) => [
            'name' => $file['name'],
            'size' => $file['size'],
            'url' => route('portal.materials.download', [$project, $material->id, $version->id, $index]),
        ]);

        // Kartdakı status — DƏRC EDİLMİŞ versiyaya görə (müştəri yalnız onu görür).
        // Versiyasız köhnə razılaşdırma cari versiyaya aid sayılır.
        $forPublished = $material->approvals
            ->filter(fn (Approval $approval) => $approval->deliverable_version_id === null
                || (int) $approval->deliverable_version_id === (int) $version->id);

        $state = MaterialApprovalState::fromApprovalStatus($forPublished->first()?->status);

        $pending = $material->approvals->first(fn (Approval $approval) => $approval->status === ApprovalStatus::Pending);

        // Razılaşdırmaya başqa (dərc edilmişdən fərqli) versiya göndərilibsə,
        // müştəri qərar verməzdən əvvəl məhz o versiyanı aça bilməlidir.
        $pendingVersionUrl = $pending && $pending->deliverable_version_id
            && (int) $pending->deliverable_version_id !== (int) $version->id
                ? route('portal.materials.download', [$project, $material->id, $pending->deliverable_version_id, 0])
                : null;

        return [
            'key' => 'material-'.$material->id,
            'material_id' => $material->id,
            'title' => $material->title,
            'label' => self::categoryLabel($material->type),
            'icon' => $material->type?->portalIcon() ?? 'other',
            'filter' => $material->type?->portalFilter() ?? 'files',
            'stage' => $material->stage?->name,
            'version' => 'v'.$version->version_number,
            'date' => $version->created_at,
            'size' => $files->sum('size') ?: null,
            'url' => $files->first()['url'] ?? null,
            'files' => $files->all(),
            'comments' => $version->comments
                ->map(fn (Comment $comment) => [
                    'author' => $comment->author?->name,
                    'body' => $comment->body,
                    'date' => $comment->created_at,
                    'mine' => $comment->author_type === 'client_user' && (int) $comment->author_id === (int) $clientUserId,
                ])
                ->all(),
            'comment_url' => route('portal.materials.comments.store', [$project, $material->id]),
            'state' => [
                'value' => $state->value,
                'label' => $this->stateLabel($state),
            ],
            'pending' => $pending,
            'pending_version_url' => $pendingVersionUrl,
            'history' => $material->approvals
                ->concat($legacyApprovals)
                ->sortByDesc(fn (Approval $approval) => $approval->decided_at ?? $approval->created_at)
                ->map(fn (Approval $approval) => [
                    'version' => $approval->deliverableVersion?->version_number ?? $approval->version,
                    'status' => $approval->status,
                    'status_label' => $approval->status->translatedLabel(),
                    'date' => $approval->decided_at ?? $approval->created_at,
                    'who' => $approval->status === ApprovalStatus::Pending ? null : $approval->clientUser?->name,
                    'comment' => $approval->status === ApprovalStatus::Pending ? null : $approval->comment,
                ])
                ->values()
                ->all(),
            'sort' => $version->created_at?->timestamp ?? 0,
        ];
    }

    private function stateLabel(MaterialApprovalState $state): string
    {
        $key = 'portal.material_state_'.$state->value;
        $translated = t($key);

        return is_string($translated) && $translated !== $key ? $translated : $state->label();
    }

    /**
     * Köçürülmədən əvvəl mənbə sənədə verilmiş razılaşdırmalar — tarixçə
     * itməsin (`Deliverable::approvalHistory()` ilə eyni məntiq, bir sorğuda).
     *
     * @param  Collection<int, Deliverable>  $materials
     * @return Collection<int, Collection<int, Approval>> material id => razılaşdırmalar
     */
    private function legacyDocumentApprovals(Collection $materials): Collection
    {
        $documentToMaterial = $materials
            ->flatMap(fn (Deliverable $material) => $material->legacyDocuments->mapWithKeys(fn (Document $doc) => [$doc->id => $material->id]));

        if ($documentToMaterial->isEmpty()) {
            return collect();
        }

        return Approval::query()
            ->where('approvable_type', (new Document)->getMorphClass())
            ->whereIn('approvable_id', $documentToMaterial->keys())
            ->whereIn('status', array_map(fn (ApprovalStatus $s) => $s->value, self::CLIENT_APPROVAL_STATUSES))
            ->with('clientUser:id,name')
            ->get()
            ->groupBy(fn (Approval $approval) => $documentToMaterial->get($approval->approvable_id));
    }
}
