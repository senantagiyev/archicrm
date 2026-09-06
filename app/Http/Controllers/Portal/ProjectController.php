<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;

class ProjectController extends Controller
{
    use ResolvesClientProjects;

    public function index()
    {
        $projects = $this->clientProjects()->latest()->get();

        if ($projects->count() === 1) {
            return redirect()->route('portal.projects.show', $projects->first());
        }

        return view('portal.projects.index', compact('projects'));
    }

    public function show(int $project)
    {
        $project = $this->clientProject($project);
        $project->load(['stages', 'manager', 'brief']);

        $pendingApprovals = $project->approvals()
            ->where('status', ApprovalStatus::Pending->value)
            ->count();

        // Lightweight counts for the portal quick-access cards.
        $briefProgress = (int) ($project->brief?->progress ?? 0);
        $documentsCount = $project->documents()->where('visible_to_client', true)->count();
        $paymentsDue = $project->payments()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Overdue->value])
            ->count();

        return view('portal.projects.show', compact(
            'project', 'pendingApprovals', 'briefProgress', 'documentsCount', 'paymentsDue'
        ));
    }
}
