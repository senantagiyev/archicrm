<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use Illuminate\Support\Facades\Storage;

class DiaryController extends Controller
{
    use ResolvesClientProjects;

    public function index(int $project)
    {
        $project = $this->clientProject($project);

        // Qaralamalar portala düşmür — yalnız dizaynerin dərc etdikləri.
        $entries = $project->diaryEntries()
            ->published()
            ->with('author')
            ->orderByDesc('published_at')
            ->get();

        return view('portal.diary', compact('project', 'entries'));
    }

    /**
     * Gündəlik fotosu — avtorizasiyadan keçərək verilir.
     *
     * Fayllar `public` diskindədir, yəni birbaşa `Storage::url()` linki sessiya
     * tələb etmir: linki ələ keçirən istənilən kənar şəxs obyektin fotosunu
     * aça bilərdi. Ona görə portal onları yalnız bu marşrutla göstərir —
     * burada həm müştəri sərhədi, həm də qeydin DƏRC olunması yoxlanılır.
     */
    public function photo(int $project, int $entry, int $index)
    {
        $project = $this->clientProject($project);

        $diaryEntry = $project->diaryEntries()->published()->findOrFail($entry);

        // İndeks URL-dən gəlir: qeydin öz massivindən kənara çıxmaq olmaz,
        // əks halda başqa qeydin faylına keçid qurmaq mümkün olardı.
        $path = collect($diaryEntry->photos ?? [])->filter()->values()->get($index);

        abort_if($path === null, 404);
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }
}
