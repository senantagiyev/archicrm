<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\Approval;
use Illuminate\Contracts\View\View;

/**
 * Cross-project approvals hub — the left-nav «Razılaşdırmalar» screen.
 *
 * The per-project list lives in {@see ApprovalController}; this one answers the
 * customer's actual question ("what is waiting for me right now?") without
 * making them open each project in turn.
 */
class GlobalApprovalController extends Controller
{
    use ResolvesClientProjects;

    public function index(): View
    {
        // Scoped at the query level: only the authenticated customer's projects.
        $projectIds = $this->clientProjects()->pluck('projects.id');

        $approvals = Approval::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', ApprovalStatus::Pending->value)
            ->with(['approvable', 'project'])
            // Soonest deadline first; undated ones last.
            ->orderByRaw('case when respond_by is null then 1 else 0 end')
            ->orderBy('respond_by')
            ->orderByDesc('id')
            ->get();

        return view('portal.hub.approvals', compact('approvals'));
    }
}
