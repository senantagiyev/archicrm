<?php

namespace App\Http\Controllers\Portal;

use App\Enums\FileCategory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Roomix-dəki «Files» tabı: layihənin bütün faylları bir yerdə, tipinə görə
 * süzülür (Media · Sənəd faylları · Keçidlər · Sənədlər).
 *
 * Fayllar əvvəl yalnız admin panelində idi — müştəri onları ancaq çatda
 * paylaşılanda görürdü və köhnə mesajlarda itirirdi.
 */
class FileController extends Controller
{
    use ResolvesClientProjects;

    /**
     * Roomix-in filtr çipləri → Archi-nin fayl kateqoriyaları.
     *
     * `voice` qəsdən yoxdur: səsli mesaj çatın funksiyasıdır və Archi-də hələ
     * yoxdur — boş çip göstərmək müştərini yanıldardı.
     *
     * @var array<string, array<int, FileCategory>>
     */
    private const FILTERS = [
        'media' => [FileCategory::Image, FileCategory::Visualization],
        'files' => [FileCategory::Other],
        'links' => [FileCategory::Link],
        'docs' => [FileCategory::Plan],
    ];

    public function index(Request $request, int $project)
    {
        $project = $this->clientProject($project);

        $filter = $request->query('filter');
        $filter = isset(self::FILTERS[$filter]) ? $filter : 'all';

        // Sayğaclar HƏMİŞƏ bütün görünən fayllara görə hesablanır, seçili
        // filtrə görə yox — əks halda çipin rəqəmi öz üstünə basanda dəyişərdi.
        $visible = $project->files()->clientVisible()->latest('id')->get();

        $counts = collect(self::FILTERS)
            ->map(fn (array $categories) => $visible->whereIn('category', $categories)->count())
            ->all();

        $files = $filter === 'all'
            ? $visible
            : $visible->whereIn('category', self::FILTERS[$filter])->values();

        return view('portal.files', compact('project', 'files', 'counts', 'filter'));
    }

    public function download(int $project, int $file)
    {
        $project = $this->clientProject($project);

        // Fayl id-si URL-dən gəlir, ona görə onu MODELƏ deyil, layihənin öz
        // əlaqəsinə görə həll edirik: yad layihənin və ya daxili faylın id-si
        // buradan 404 ilə qayıdır.
        $file = $project->files()->clientVisible()->findOrFail($file);

        $ext = pathinfo($file->file_path, PATHINFO_EXTENSION);
        $name = Str::of($file->title ?: 'file')->ascii()->replaceMatches('/[^A-Za-z0-9 _-]/', '')->trim();
        $filename = ($name->isEmpty() ? 'file' : $name).($ext ? '.'.$ext : '');

        abort_unless(Storage::disk('public')->exists($file->file_path), 404);

        return Storage::disk('public')->download($file->file_path, $filename);
    }
}
