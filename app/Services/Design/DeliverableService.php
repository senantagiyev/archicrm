<?php

namespace App\Services\Design;

use App\Enums\DeliverableStatus;
use App\Enums\DeliverableVersionStatus;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * TZ v2.0 §7.11–7.12, §8.9 — deliverable versioning lifecycle.
 *
 * Material kartları (TZ «Layihə naviqasiyası» §6): razılaşdırma konkret
 * VERSİYA üzrədir. Qərar həmin versiyaya tətbiq olunur, cari versiyaya yox —
 * müştəri v2-ni təsdiqləyərkən dizayner artıq daxili v3 üzərində işləyə bilər.
 */
class DeliverableService
{
    /**
     * Create a new version. When a previous non-terminal version exists it is
     * superseded. Returns the fresh draft version and points the deliverable at it.
     *
     * Əvəzlənmir: müştəriyə dərc edilmiş versiya (müştəri hələ onu görür) və
     * cavab gözləyən versiya (qərar ona tətbiq olunmalıdır).
     *
     * @param  array<int, array{path: string, name?: string|null, size?: int|null, mime?: string|null}>  $attachments
     */
    public function createVersion(
        Deliverable $deliverable,
        ?string $filePath,
        ?User $by = null,
        ?string $summary = null,
        array $attachments = [],
        ?Model $uploadedBy = null,
    ): DeliverableVersion {
        return DB::transaction(function () use ($deliverable, $filePath, $by, $summary, $attachments, $uploadedBy) {
            $latest = $deliverable->versions()->first(); // ordered desc by number
            $next = ($latest?->version_number ?? 0) + 1;

            $supersedable = [
                DeliverableVersionStatus::Draft,
                DeliverableVersionStatus::InternalReview,
                DeliverableVersionStatus::ReadyForClient,
                DeliverableVersionStatus::RevisionRequired,
            ];

            if ($latest
                && in_array($latest->status, $supersedable, true)
                && (int) $latest->id !== (int) $deliverable->published_version_id) {
                $latest->forceFill(['status' => DeliverableVersionStatus::Superseded])->save();
            }

            $uploadedBy ??= $by;

            $version = $deliverable->versions()->create([
                'version_number' => $next,
                'file_path' => $filePath ?? ($attachments[0]['path'] ?? null),
                'attachments' => $attachments === [] ? null : array_values($attachments),
                'created_by_user_id' => $by?->id,
                'uploaded_by_type' => $uploadedBy?->getMorphClass(),
                'uploaded_by_id' => $uploadedBy?->getKey(),
                'status' => DeliverableVersionStatus::Draft,
                'change_summary' => $summary,
                'based_on_version_id' => $latest?->id,
            ]);

            $deliverable->forceFill([
                'current_version_id' => $version->id,
                'status' => DeliverableStatus::InProgress,
            ])->save();

            return $version;
        });
    }

    /**
     * Move the version (default: current) to sent_for_approval and the
     * deliverable to waiting_client. Əvvəlki cavabsız göndərişin versiyası
     * əvəzlənir — `ApprovalService::request()` həmin sorğunu artıq geri çəkib.
     */
    public function markSentForApproval(Deliverable $deliverable, ?DeliverableVersion $version = null): void
    {
        $version ??= $deliverable->currentVersion;
        if (! $version) {
            return;
        }

        // draft/internal_review/ready_for_client → walk forward to sent_for_approval.
        foreach ([DeliverableVersionStatus::InternalReview, DeliverableVersionStatus::ReadyForClient, DeliverableVersionStatus::SentForApproval] as $target) {
            if ($version->status === $target) {
                continue;
            }
            if ($version->status->canTransitionTo($target)) {
                $version->transitionTo($target);
            }
        }

        $deliverable->versions()
            ->whereKeyNot($version->id)
            ->where('status', DeliverableVersionStatus::SentForApproval->value)
            ->get()
            ->each(fn (DeliverableVersion $stale) => $stale->transitionTo(DeliverableVersionStatus::Superseded));

        $deliverable->forceFill(['status' => DeliverableStatus::WaitingClient])->save();
    }

    /**
     * Customer approved → lock the version (default: current) and mark the
     * deliverable approved. Mərhələyə TOXUNMUR: ayrı materialın təsdiqi bütün
     * mərhələni tamamlamır (TZ «Layihə naviqasiyası» §4).
     */
    public function onApproved(Deliverable $deliverable, ?DeliverableVersion $version = null): void
    {
        $version ??= $deliverable->currentVersion;
        if ($version && $version->status === DeliverableVersionStatus::SentForApproval) {
            $version->transitionTo(DeliverableVersionStatus::Approved);
            $version->transitionTo(DeliverableVersionStatus::Locked);
        }
        $deliverable->forceFill(['status' => DeliverableStatus::Approved])->save();
    }

    /** Customer requested a revision → version back to revision_required. */
    public function onRevisionRequired(Deliverable $deliverable, ?DeliverableVersion $version = null): void
    {
        $version ??= $deliverable->currentVersion;
        if ($version && $version->status->canTransitionTo(DeliverableVersionStatus::RevisionRequired)) {
            $version->transitionTo(DeliverableVersionStatus::RevisionRequired);
        }
        $deliverable->forceFill(['status' => DeliverableStatus::RevisionRequired])->save();
    }
}
