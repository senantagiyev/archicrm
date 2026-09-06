<?php

namespace App\Services\Design;

use App\Enums\DeliverableStatus;
use App\Enums\DeliverableVersionStatus;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TZ v2.0 §7.11–7.12, §8.9 — deliverable versioning lifecycle.
 */
class DeliverableService
{
    /**
     * Create a new version. When a previous non-terminal version exists it is
     * superseded. Returns the fresh draft version and points the deliverable at it.
     */
    public function createVersion(Deliverable $deliverable, ?string $filePath, ?User $by = null, ?string $summary = null): DeliverableVersion
    {
        return DB::transaction(function () use ($deliverable, $filePath, $by, $summary) {
            $latest = $deliverable->versions()->first(); // ordered desc by number
            $next = ($latest?->version_number ?? 0) + 1;

            // Supersede the previous version if it is not approved/locked.
            if ($latest && ! $latest->status->isImmutable() && $latest->status !== DeliverableVersionStatus::Superseded) {
                $latest->forceFill(['status' => DeliverableVersionStatus::Superseded])->save();
            }

            $version = $deliverable->versions()->create([
                'version_number' => $next,
                'file_path' => $filePath,
                'created_by_user_id' => $by?->id,
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

    /** Move the current version to sent_for_approval and the deliverable to waiting_client. */
    public function markSentForApproval(Deliverable $deliverable): void
    {
        $version = $deliverable->currentVersion;
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

        $deliverable->forceFill(['status' => DeliverableStatus::WaitingClient])->save();
    }

    /** Customer approved → lock the current version and mark the deliverable approved. */
    public function onApproved(Deliverable $deliverable): void
    {
        $version = $deliverable->currentVersion;
        if ($version && $version->status === DeliverableVersionStatus::SentForApproval) {
            $version->transitionTo(DeliverableVersionStatus::Approved);
            $version->transitionTo(DeliverableVersionStatus::Locked);
        }
        $deliverable->forceFill(['status' => DeliverableStatus::Approved])->save();
    }

    /** Customer requested a revision → version back to revision_required. */
    public function onRevisionRequired(Deliverable $deliverable): void
    {
        $version = $deliverable->currentVersion;
        if ($version && $version->status->canTransitionTo(DeliverableVersionStatus::RevisionRequired)) {
            $version->transitionTo(DeliverableVersionStatus::RevisionRequired);
        }
        $deliverable->forceFill(['status' => DeliverableStatus::RevisionRequired])->save();
    }
}
