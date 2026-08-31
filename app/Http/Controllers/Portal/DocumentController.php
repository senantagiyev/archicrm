<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        $ext = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $name = Str::of($document->title)->ascii()->replaceMatches('/[^A-Za-z0-9 _-]/', '')->trim();
        $filename = ($name->isEmpty() ? 'document' : $name).($ext ? '.'.$ext : '');

        return Storage::disk('public')->download($document->file_path, $filename);
    }
}
