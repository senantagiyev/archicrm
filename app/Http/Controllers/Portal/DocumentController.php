<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    use ResolvesClientProjects;

    public function index(int $project)
    {
        $project = $this->clientProject($project);

        $documents = $project->documents()
            ->where('visible_to_client', true)
            ->latest()
            ->get();

        return view('portal.documents', compact('project', 'documents'));
    }

    public function download(int $project, int $document)
    {
        $project = $this->clientProject($project);

        $document = $project->documents()
            ->where('visible_to_client', true)
            ->findOrFail($document);

        return Storage::disk('public')->download($document->file_path, $document->title.'.'.pathinfo($document->file_path, PATHINFO_EXTENSION));
    }
}
