<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\Deliverable;
use App\Services\Design\MaterialService;
use App\Support\MaterialFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Material kartının faylı və şərhi müştəri üçün.
 *
 * Müştəri YALNIZ dərc edilmiş versiyanı və ona razılaşdırmaya göndərilmiş
 * versiyaları aça bilər ({@see Deliverable::clientVersionIds()}).
 * Daha yeni daxili versiyanın id-si URL-də yazılsa belə 404 qayıdır.
 */
class MaterialController extends Controller
{
    use ResolvesClientProjects;

    public function download(int $project, int $material, int $version, int $index): StreamedResponse
    {
        $project = $this->clientProject($project);

        $material = $project->deliverables()->findOrFail($material);

        abort_unless(in_array($version, $material->clientVersionIds(), true), 404);

        return MaterialFiles::download($material->versions()->findOrFail($version), $index);
    }

    /**
     * Müştərinin şərhi DƏRC EDİLMİŞ versiyaya (qəbul meyarı 7).
     *
     * Versiya serverdə seçilir, formadan gəlmir: müştəri daxili versiyaya şərh
     * yaza bilməz. Şərh həmişə müştəriyə açıqdır (`is_internal = false`) —
     * heyət onu panelin kartında görür. Material dərc edilməyibsə 404:
     * daxili materialın varlığı da bilinməməlidir.
     */
    public function comment(Request $request, int $project, int $material, MaterialService $materials): RedirectResponse
    {
        $project = $this->writableClientProject($project);

        $material = $project->deliverables()
            ->whereNotNull('published_version_id')
            ->with('publishedVersion')
            ->findOrFail($material);

        abort_if($material->publishedVersion === null, 404);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'body.required' => t('portal.material_comment_required'),
        ]);

        $materials->addComment($material->publishedVersion, Auth::guard('customer')->user(), $validated['body'], false);

        return redirect()
            ->to(route('portal.files', $project).'#material-'.$material->id)
            ->with('status', t('portal.material_comment_sent'));
    }
}
