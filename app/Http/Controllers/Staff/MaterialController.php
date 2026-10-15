<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\DeliverableVersion;
use App\Support\MaterialFiles;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Fayllar» material kartının faylları heyət üçün. Public disk URL-i ilə yox,
 * policy-dən keçən marşrutla (FilesDocuments domeni + layihə üzvlüyü).
 */
class MaterialController extends Controller
{
    public function download(DeliverableVersion $version, int $index = 0): StreamedResponse
    {
        // Silinmiş material: versiya qalır, amma faylı açılmamalıdır.
        abort_if($version->deliverable === null, 404, 'Material tapılmadı.');

        Gate::authorize('view', $version);

        return MaterialFiles::download($version, $index);
    }
}
