<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Roomix-dəki «Procurement list» (`/complectation/<id>`) səhifəsinin qarşılığı.
 *
 * Sətirlər artıq `procurement_items` cədvəlindədir — yeni model qurulmur.
 * Komplektasiya siyahısında studiyanın daxili pozisiyaları da olur (alternativ
 * variantlar, təchizatçı marjası, hələ razılaşdırılmamış sətirlər), ona görə
 * müştəriyə `Document`/`BudgetLine` ilə EYNİ məntiqlə yalnız
 * `visible_to_client = true` sətirlər verilir. Filtr həm səhifədə, həm də CSV
 * ixracında tətbiq olunur ki, ixrac gizli sətri arxa qapıdan çıxarmasın.
 */
class ProcurementController extends Controller
{
    use ResolvesClientProjects;

    /**
     * CSV başlıqları (foto və əlavə fayllar CSV-də verilmir). Spesifikasiyadan
     * gələn sahələr (brend, model, xüsusiyyətlər, seçim statusu) SONA əlavə
     * olunub — köhnə sütunların yeri dəyişmir, əvvəlki ixracla uyğun qalır.
     * Təchizatçı mayası (`supplier_cost`) daxilidir və heç vaxt ixraca düşmür.
     */
    private const CSV_HEADERS = [
        'Ad', 'Analoq', 'Kateqoriya', 'Otaq', 'Ölçü vahidi', 'Say',
        'Vahidin qiyməti', 'Cəmi', 'Endirim %', 'Endirimlə', 'Mövcudluq',
        'Razılaşdırma', 'Alınıb', 'Çatdırılma', 'Keçid',
        'Çatdırılma/quraşdırma', 'Ehtiyat %', 'Şərh',
        'Brend', 'Model', 'Xüsusiyyətlər', 'Seçim statusu',
    ];

    public function index(int $project)
    {
        $project = $this->clientProject($project);

        $items = $this->clientItems($project);

        // Cəmlər DB-dən deyil, məhz göstərilən sətirlərdən yığılır — beləliklə
        // ekrandakı sətirlərin cəmi ilə aşağıdakı yekunlar həmişə üst-üstə düşür.
        $totalBeforeDiscount = $items->sum(fn (ProcurementItem $item) => (float) $item->total);
        $totalWithDiscount = $items->sum(fn (ProcurementItem $item) => $item->totalWithDiscount());

        return view('portal.procurement', compact(
            'project', 'items', 'totalBeforeDiscount', 'totalWithDiscount'
        ));
    }

    /**
     * Roomix-də bu düymə «Download Excel»dir; bizdə CSV-dir — Excel CSV-ni
     * birbaşa açır və bunun üçün əlavə composer paketi (xlsx yazıcı) lazım
     * deyil, yəni yeni asılılıq və onun təhlükəsizlik yükü gəlmir.
     */
    public function export(int $project): StreamedResponse
    {
        $project = $this->clientProject($project);

        $items = $this->clientItems($project);
        $totalBeforeDiscount = $items->sum(fn (ProcurementItem $item) => (float) $item->total);
        $totalWithDiscount = $items->sum(fn (ProcurementItem $item) => $item->totalWithDiscount());

        $filename = 'komplektasiya-'.$project->id.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($items, $totalBeforeDiscount, $totalWithDiscount): void {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM: Excel BOM olmayan faylı sistem kodlaşdırması ilə açır
            // və Azərbaycan hərfləri (ə, ğ, ş, ı) korlanır.
            fwrite($out, "\xEF\xBB\xBF");

            // Ayırıcı nöqtəli vergüldür: Excel-in Azərbaycan/Rusiya regional
            // ayarlarında siyahı ayırıcısı məhz `;`-dir, vergüllü fayl isə
            // bütün sətri bir xanaya yığır.
            fputcsv($out, self::CSV_HEADERS, ';');

            foreach ($items as $item) {
                // Xanalar `Csv::row()`-dan keçir: `=`, `+`, `-`, `@` ilə başlayan
                // dəyəri Excel DÜSTUR kimi icra edir, bu fayl isə müştəriyə gedir.
                fputcsv($out, Csv::row([
                    $item->name,
                    $item->analog ?? '',
                    $item->category ?? '',
                    $item->room ?? '',
                    $item->unit ?? '',
                    $this->money($item->qty),
                    $this->money($item->price),
                    $this->money($item->total),
                    $item->discount_percent === null ? '' : $this->money($item->discount_percent),
                    $this->money($item->totalWithDiscount()),
                    $item->availability ?? '',
                    $item->approval_status?->label() ?? '',
                    $item->paid ? 'Bəli' : 'Xeyr',
                    $item->delivery_date?->format('d.m.Y') ?? '',
                    $item->url ?? '',
                    $this->money($item->delivery_assembly_price),
                    $this->money($item->reserve_percent),
                    // Yalnız müştəri üçün nəzərdə tutulan şərh; `cancel_comment`
                    // (daxili ləğv səbəbi) qəsdən ixraca DÜŞMÜR.
                    $item->comment ?? '',
                    $item->brand ?? '',
                    $item->model ?? '',
                    $item->characteristics ?? '',
                    $item->selection_status?->label() ?? '',
                ]), ';');
            }

            // Yekun sətirləri: faylı Excel-də açan adam cəmi əl ilə yığmasın.
            // Yer əvvəlki kimidir (sonuncu köhnə sütunlar), yeni sütunlar boş.
            $pad = fn (array $tail) => array_pad(array_pad($tail, -18, ''), count(self::CSV_HEADERS), '');

            fputcsv($out, $pad(['Endirimdən əvvəl', $this->money($totalBeforeDiscount)]), ';');
            fputcsv($out, $pad(['Endirimlə', $this->money($totalWithDiscount)]), ';');

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Pozisiya fotosu — avtorizasiyadan keçərək verilir.
     *
     * Fayllar `public` diskindədir, yəni birbaşa `Storage::disk('public')->url()`
     * linki sessiya tələb etmir: linki ələ keçirən kənar şəxs müştərinin
     * komplektasiya fotosunu aça bilərdi. Ona görə portal onları yalnız bu
     * marşrutla göstərir — burada həm müştəri sərhədi, həm də sətrin
     * `visible_to_client` bayrağı yoxlanılır (`DiaryController::photo()` ilə
     * eyni məntiq).
     */
    public function photo(int $project, int $item)
    {
        $project = $this->clientProject($project);

        $record = $this->clientItemsQuery($project)->findOrFail($item);

        abort_unless(self::readableOnPublicDisk($record->photo_path), 404, 'Foto tapılmadı.');

        return $this->imageResponse($record->photo_path);
    }

    /**
     * Pozisiyanın əlavəsi (şəkil / texniki sənəd) — foto ilə eyni qayda:
     * müştəri sərhədi + `visible_to_client`, indeks yalnız sətrin öz
     * massivindən. Rastr şəkil inline açılır, qalanı endirilir.
     */
    public function attachment(int $project, int $item, int $index)
    {
        $project = $this->clientProject($project);

        $record = $this->clientItemsQuery($project)->findOrFail($item);

        $path = self::attachmentPaths($record)[$index] ?? null;

        abort_if($path === null, 404);
        abort_unless(self::readableOnPublicDisk($path), 404, 'Fayl tapılmadı.');

        return $this->imageResponse($path);
    }

    /**
     * `attachments` sütunu FileUpload-dan yol siyahısıdır; köçürülmüş köhnə
     * sətirlərdə `['path' => …]` forması da ola bilər.
     *
     * @return list<string>
     */
    public static function attachmentPaths(ProcurementItem $item): array
    {
        return collect($item->attachments ?? [])
            ->map(fn ($file) => is_array($file) ? ($file['path'] ?? null) : $file)
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values()
            ->all();
    }

    /**
     * Diskin kökündən kənara çıxan yol (`../../../../.env`) üçün Flysystem
     * `exists()`-in ÖZÜNDƏN `PathTraversalDetected` atır — yəni sadə
     * `exists()` yoxlaması qorumurdu, sorğu 404 yox, 500 verirdi və
     * `APP_DEBUG` açıq olanda server yollarını açırdı. Fayl heç vaxt
     * verilmirdi, amma xəta emal olunmamış qalırdı.
     * `DiaryController::readableOnPublicDisk()` ilə eyni məntiq.
     */
    private static function readableOnPublicDisk(?string $path): bool
    {
        if (blank($path)) {
            return false;
        }

        try {
            return Storage::disk('public')->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Şəkil cavabı — `nosniff` və tip ağ siyahısı ilə
     * (`DiaryController::imageResponse()` ilə eyni məntiq).
     *
     * Fayl tətbiqin ÖZ origin-indən `inline` verilir: diskə HTML/SVG düşsə,
     * brauzer onu portal origin-ində sənəd kimi açar və içindəki skript
     * müştərinin sessiyası ilə işləyərdi. `nosniff` brauzerə tipi təxmin
     * etməyi qadağan edir, ağ siyahı isə yalnız rastr şəkilləri inline
     * buraxır (SVG qəsdən yoxdur — içində <script> ola bilər); qalanı
     * `attachment` kimi endirilir və icra olunmur.
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

    /**
     * Müştəriyə açıq komplektasiya sətirləri.
     *
     * @return Collection<int, ProcurementItem>
     */
    private function clientItems(Project $project): Collection
    {
        return $this->clientItemsQuery($project)->get();
    }

    private function clientItemsQuery(Project $project)
    {
        return $project->procurementItems()
            ->where('visible_to_client', true)
            ->orderBy('id');
    }

    /** CSV-də rəqəmlər qrup ayırıcısız verilir — Excel onları mətn kimi oxumasın. */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
