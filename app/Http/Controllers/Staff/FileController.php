<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ProjectFile;
use App\Support\MaterialFiles;
use Illuminate\Http\RedirectResponse;
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
    public function download(ProjectFile $file): StreamedResponse|RedirectResponse
    {
        Gate::authorize('view', $file);

        // Material kartına köçürülmüş fayl: köhnə link materialın HƏMİN faylına
        // yönləndirilir (köçürmənin yaratdığı birinci versiya). Hədəf marşrut
        // policy-ni yenidən yoxlayır.
        if ($file->migrated_deliverable_id !== null) {
            $version = $file->migratedDeliverable?->versions()->reorder()->orderBy('version_number')->first();

            abort_if($version === null, 404, 'Fayl tapılmadı.');

            return redirect()->route('materials.download', ['version' => $version, 'index' => 0]);
        }

        abort_unless(MaterialFiles::readable($file->file_path), 404, 'Fayl tapılmadı.');

        return Storage::disk('public')->download(
            $file->file_path,
            $file->title ? $file->title.'.'.pathinfo($file->file_path, PATHINFO_EXTENSION) : basename($file->file_path),
        );
    }
}
