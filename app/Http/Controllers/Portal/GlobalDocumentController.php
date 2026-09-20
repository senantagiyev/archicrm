<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\Document;
use Illuminate\Contracts\View\View;

/**
 * Cross-project documents hub — the left-nav «Sənədlər» screen.
 *
 * Downloads still go through {@see DocumentController::download()}, so the
 * per-project scoping and the `visible_to_client` gate stay in one place.
 */
class GlobalDocumentController extends Controller
{
    use ResolvesClientProjects;

    public function index(): View
    {
        $projectIds = $this->clientProjects()->pluck('projects.id');

        $documents = Document::query()
            ->whereIn('project_id', $projectIds)
            ->where('visible_to_client', true)
            ->with('project')
            ->latest()
            ->get();

        return view('portal.hub.documents', compact('documents'));
    }
}
