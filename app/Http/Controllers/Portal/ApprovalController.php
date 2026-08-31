<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\Approval;
use App\Services\Approvals\ApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApprovalController extends Controller
{
    use ResolvesClientProjects;

    public function index(int $project)
    {
        $project = $this->clientProject($project);

        $approvals = $project->approvals()
            ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value])
            ->with(['approvable'])
            ->orderByRaw("field(status, 'pending') desc")
            ->latest()
            ->get();

        return view('portal.approvals', compact('project', 'approvals'));
    }

    public function decide(Request $request, Approval $approval, ApprovalService $service)
    {
        // Scoping: the approval must belong to one of this customer's projects.
        $this->clientProject($approval->project_id);

        abort_unless($approval->status === ApprovalStatus::Pending, 403);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'comment' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000'],
        ], [
            'comment.required_if' => t('portal.reject_comment_required'),
        ]);

        $service->decide(
            $approval,
            $validated['decision'] === 'approve',
            $validated['comment'] ?? null,
            Auth::guard('customer')->user(),
        );

        return back()->with('status', $validated['decision'] === 'approve'
            ? t('portal.approved_ok')
            : t('portal.rejected_ok'));
    }
}
