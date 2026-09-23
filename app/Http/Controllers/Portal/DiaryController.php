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

        return $this->imageResponse($path);
    }

    /**
     * Şəkil cavabı — `nosniff` və tip ağ siyahısı ilə.
     *
     * Fayl tətbiqin ÖZ origin-indən `inline` verilir. Uzantı yoxlamasından
     * keçib diskə HTML və ya SVG düşsə, brauzer onu portal origin-ində SƏNƏD
     * kimi açar və içindəki skript müştərinin sessiyası ilə işləyərdi. Ona görə:
     *  • `X-Content-Type-Options: nosniff` — brauzer məzmuna baxıb tipi
     *    «təxmin etmir», yalnız bizim verdiyimiz `Content-Type`-a inanır;
     *  • ağ siyahı — yalnız rastr şəkil tipləri inline gedir. SVG QƏSDƏN
     *    siyahıda yoxdur: onun içində <script> ola bilər. Siyahıdan kənar hər
     *    şey `attachment` kimi verilir, yəni brauzerdə icra olunmur.
     *
     * Eyni məntiq `ProcurementController::imageResponse()`-dadır — iki marşrut
     * da `public` diskindən müştəriyə fayl verir.
     */
    private function imageResponse(string $path)
    {
        $mime = Storage::disk('public')->mimeType($path) ?: 'application/octet-stream';

        $inline = in_array($mime, [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
        ], true);

        return Storage::disk('public')->response(
            $path,
            null,
            [
                'Content-Type' => $inline ? $mime : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }
}
