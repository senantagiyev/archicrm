<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Approval;
use App\Models\BudgetLine;
use App\Models\ClientUser;
use App\Models\Deliverable;
use App\Models\Document;
use App\Models\ProcurementItem;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ApprovalDecided;
use App\Notifications\ApprovalRequested;
use App\Services\Automation\AutomationEngine;
use App\Services\Design\DeliverableService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ApprovalService
{
    /**
     * Send a row (smeta line, procurement item, stage or document) to the
     * customer for approval. Notifies every portal account of the client.
     */
    public function request(Model $approvable, User $requestedBy, ?Carbon $respondBy = null): Approval
    {
        $project = $approvable->project;

        // Supersede a previous pending request for the same row.
        Approval::query()
            ->where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey())
            ->where('status', ApprovalStatus::Pending->value)
            ->update(['status' => ApprovalStatus::Draft->value]);

        $approval = Approval::create([
            'approvable_type' => $approvable->getMorphClass(),
            'approvable_id' => $approvable->getKey(),
            'project_id' => $project->id,
            'requested_by_user_id' => $requestedBy->id,
            'status' => ApprovalStatus::Pending,
            'respond_by' => $respondBy,
        ]);

        $this->setSubjectStatus($approvable, ApprovalStatus::Pending);

        foreach ($project->client->clientUsers as $clientUser) {
            $clientUser->notify(new ApprovalRequested($approval));
        }

        return $approval;
    }

    /**
     * Record the customer's decision. Rejection requires a comment (TZ §5.7).
     */
    public function decide(Approval $approval, bool $approved, ?string $comment, ?ClientUser $decidedBy = null): Approval
    {
        if (! $approved && blank($comment)) {
            throw new InvalidArgumentException('Rədd edərkən şərh məcburidir.');
        }

        $approval->update([
            'status' => $approved ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
            'comment' => $comment,
            'client_user_id' => $decidedBy?->id ?? $approval->client_user_id,
            'decided_at' => now(),
        ]);

        $approval->loadMissing('approvable');
        $this->setSubjectStatus($approval->approvable, $approval->status);

        $approval->requestedBy?->notify(new ApprovalDecided($approval));

        if (! $approved) {
            $this->createRevisionTask($approval, $comment);
        }

        return $approval;
    }

    /**
     * Əlavə B rule 18: a rejected approval spawns a revision task for the requester.
     * Tasks require a stage; when the project has one we attach the task there,
     * otherwise the ApprovalDecided notification above is the only signal.
     */
    private function createRevisionTask(Approval $approval, ?string $comment): void
    {
        if (! app(AutomationEngine::class)->isEnabled('rule-18') || ! $approval->project_id) {
            return;
        }

        $stage = Stage::query()
            ->where('project_id', $approval->project_id)
            ->orderBy('position')
            ->first();

        if (! $stage) {
            return;
        }

        Task::create([
            'stage_id' => $stage->id,
            'project_id' => $approval->project_id,
            'title' => 'Düzəliş: '.$approval->subjectLabel(),
            'description' => $comment,
            'assignee_user_id' => $approval->requested_by_user_id,
            'author_user_id' => $approval->requested_by_user_id,
            'status' => TaskStatus::Todo->value,
            'priority' => TaskPriority::High->value,
        ]);
    }

    private function setSubjectStatus(Model $approvable, ApprovalStatus $status): void
    {
        match (true) {
            // forceFill: approval_status is deliberately non-fillable (audit HIGH-2).
            $approvable instanceof BudgetLine,
            $approvable instanceof ProcurementItem => $approvable->forceFill(['approval_status' => $status])->save(),
            // Deliverables run their own version state machine (TZ §9.2).
            $approvable instanceof Deliverable => $this->applyDeliverableDecision($approvable, $status),
            $approvable instanceof Stage, $approvable instanceof Document => null,
            default => throw new InvalidArgumentException('Bu obyekt razılaşdırıla bilməz: '.$approvable::class),
        };
    }

    private function applyDeliverableDecision(Deliverable $deliverable, ApprovalStatus $status): void
    {
        $service = app(DeliverableService::class);

        match ($status) {
            ApprovalStatus::Pending => $service->markSentForApproval($deliverable),
            ApprovalStatus::Approved => $service->onApproved($deliverable),
            ApprovalStatus::Rejected => $service->onRevisionRequired($deliverable),
            default => null,
        };
    }
}
