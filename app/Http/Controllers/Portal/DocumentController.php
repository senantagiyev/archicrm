<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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

        // Roomix sənəd siyahısında hələ hazır olmayan sənədlər də görünür
        // («Not started»): müştəri nəyin gözlənildiyini bilir və hər dəfə
        // dizaynerdən soruşmur. Yalnız müqavilə axınının SABİT sənədləri —
        // ixtiyari yüklənən fayllar üçün slot uydurmuruq.
        $present = $documents->pluck('type')->all();

        $missing = collect([DocumentType::TechnicalSpec, DocumentType::Contract, DocumentType::Act])
            ->reject(fn (DocumentType $type) => in_array($type, $present, true))
            ->values();

        // Roomix-də smeta və komplektasiya fayl deyil, CANLI sənəd səhifəsidir
        // («12 items · 552 140 ₽») və məhz sənədlər siyahısından açılır. Say və
        // məbləğ burada göstərilir ki, müştəri səhifəni açmadan vəziyyəti bilsin.
        $estimateLines = $project->budgetLines()->where('visible_to_client', true)->get();
        $procurementItems = $project->procurementItems()->where('visible_to_client', true)->get();

        $sheets = [
            [
                'url' => route('portal.estimate', $project),
                'label' => t('portal.nav_estimate'),
                'count' => $estimateLines->count(),
                'total' => (float) $estimateLines->sum('total'),
            ],
            [
                'url' => route('portal.procurement', $project),
                'label' => t('portal.nav_procurement'),
                'count' => $procurementItems->count(),
                'total' => $procurementItems->sum(fn ($i) => (float) $i->totalWithDiscount()),
            ],
        ];

        $pendingApprovals = $project->approvals()
            ->where('status', ApprovalStatus::Pending->value)
            ->count();

        return view('portal.documents', compact(
            'project', 'documents', 'missing', 'sheets', 'pendingApprovals',
        ));
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

        // Sətir var, fayl yoxdur — bu, 500 üçün əsas deyil. Yoxlama olmadan
        // `Storage::download()` Flysystem-in `UnableToRetrieveMetadata`
        // istisnasını atırdı və müştəri sınmış səhifə görürdü (sahibin özü
        // `/portal/projects/4/documents/2/download` ünvanında bunu tutmuşdu).
        // Fayl əl ilə silinə, köçürülə və ya natamam bərpa oluna bilər; belə
        // halda düzgün cavab «tapılmadı»dır. `FileController::download()` bu
        // yoxlamanı onsuz da edirdi — iki yolun fərqi təsadüfi idi.
        abort_unless(self::readableOnPublicDisk($document->file_path), 404, 'Sənədin faylı tapılmadı.');

        return Storage::disk('public')->download($document->file_path, $filename);
    }

    /**
     * Yol `public` diskində oxunaqlıdırmı — İSTİSNA ATMADAN.
     *
     * İki ayrı qəza bir yerdə bağlanır:
     *  • fayl yoxdur (sətir var, fayl silinib/köçürülüb) — sahibin özü
     *    `/portal/projects/4/documents/2/download`-da 500 tutmuşdu;
     *  • yol disk kökündən kənara çıxır (`../../.env` kimi). Belə sətir
     *    idxaldan və ya köhnə məlumatdan gələ bilər; Flysystem faylı VERMİR,
     *    amma `PathTraversalDetected` atır — həm də `exists()` çağırışının
     *    ÖZÜNDƏN, ona görə sadə `exists()` yoxlaması 500-ü aradan qaldırmır.
     *
     * Hər iki halda düzgün cavab «tapılmadı»dır: müştəriyə sınmış səhifə deyil,
     * anlaşılan 404 qayıtmalıdır və `APP_DEBUG` açıq mühitdə istisna izi
     * (disk kökü, tətbiq yolları) sızmamalıdır.
     */
    private static function readableOnPublicDisk(?string $path): bool
    {
        if (blank($path)) {
            return false;
        }

        try {
            return Storage::disk('public')->exists($path);
        } catch (Throwable) {
            return false;
        }
    }
}
