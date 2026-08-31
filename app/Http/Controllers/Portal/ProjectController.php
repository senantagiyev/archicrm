<?php

namespace App\Http\Controllers\Portal;

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
        $project->load(['stages', 'manager']);

        $pendingApprovals = $project->approvals()
            ->where('status', \App\Enums\ApprovalStatus::Pending->value)
            ->count();

        return view('portal.projects.show', compact('project', 'pendingApprovals'));
    }
}
