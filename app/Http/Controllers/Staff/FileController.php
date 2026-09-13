<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ProjectFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Project files are served through here rather than from the public storage
 * symlink. A direct /storage/... URL carried no session, no tenant scope and no
 * FileVisibility check, so an "Internal" drawing was readable by anyone holding
 * the link — including a staff member long since removed from the project.
 */
class FileController extends Controller
{
    public function download(ProjectFile $file): StreamedResponse
    {
        Gate::authorize('view', $file);

        abort_unless(Storage::disk('public')->exists($file->file_path), 404, 'Fayl tapılmadı.');

        return Storage::disk('public')->download(
            $file->file_path,
            $file->title ? $file->title.'.'.pathinfo($file->file_path, PATHINFO_EXTENSION) : basename($file->file_path),
        );
    }
}
