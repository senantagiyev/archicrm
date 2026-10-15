<?php

namespace App\Http\Controllers\Portal;

use App\Enums\DeliverableType;
use App\Enums\FileCategory;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\Deliverable;
use App\Models\ProjectFile;
use App\Services\Portal\ClientMaterialCards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Roomix-dəki «Files» tabı: layihənin bütün faylları bir yerdə, tipinə görə
 * süzülür (Media · Sənəd faylları · Keçidlər · Sənədlər).
 *
 * Fayllar əvvəl yalnız admin panelində idi — müştəri onları ancaq çatda
 * paylaşılanda görürdü və köhnə mesajlarda itirirdi.
 *
 * Material kartları (TZ «Layihə naviqasiyası» §5): siyahının əsas mənbəyi
 * artıq dərc edilmiş materiallardır — hər kart bir sətir, YALNIZ dərc edilmiş
 * versiyanın faylları və müştəriyə açıq şərhləri ilə. Daha yeni daxili versiya
 * və daxili şərhlər buraya düşmür. Köçürülməmiş köhnə fayllar (əgər qalıbsa)
 * əvvəlki qaydada göstərilir.
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

    public function index(Request $request, int $project, ClientMaterialCards $cards)
    {
        $project = $this->clientProject($project);

        $filter = $request->query('filter');
        $filter = isset(self::FILTERS[$filter]) ? $filter : 'all';

        // Mərhələ və kateqoriya süzgəcləri («Mərhələlər» → «Materialları aç»).
        // Mərhələ yalnız BU layihənin mərhələsi ola bilər; yad / uydurma dəyər
        // səssizcə süzgəcsiz siyahıya qayıdır (səhifə sınmır, yad ad görünmür).
        $stages = $project->stages()->orderBy('position')->orderBy('id')->get(['id', 'name', 'position']);
        $stage = $stages->firstWhere('id', (int) $request->query('stage'));
        $category = DeliverableType::tryFrom((string) $request->query('category'));

        // Sayğaclar HƏMİŞƏ bütün görünən fayllara görə hesablanır, seçili
        // çipə görə yox — əks halda çipin rəqəmi öz üstünə basanda dəyişərdi.
        // Köhnə fayllar mərhələyə bağlı deyil: mərhələ süzgəcində düşmürlər.
        $visible = $cards->cards($project, $stage, $category)
            ->concat($stage ? collect() : $this->legacyItems($project, $category))
            ->sortByDesc('sort')
            ->values();

        $counts = collect(array_keys(self::FILTERS))
            ->mapWithKeys(fn (string $key) => [$key => $visible->where('filter', $key)->count()])
            ->all();

        $files = $filter === 'all'
            ? $visible
            : $visible->where('filter', $filter)->values();

        // Kateqoriya seçimi yalnız müştəriyə dərc edilmiş materiallarda olan
        // növlərdən qurulur — boş seçim göstərilmir.
        $categories = $project->deliverables()
            ->whereNotNull('published_version_id')
            ->distinct()
            ->pluck('type')
            ->map(fn ($type) => $type instanceof DeliverableType ? $type : DeliverableType::tryFrom((string) $type))
            ->filter()
            ->mapWithKeys(fn (DeliverableType $type) => [$type->value => ClientMaterialCards::categoryLabel($type)])
            ->sort()
            ->all();

        // Arxivlənmiş layihədə qərar və şərh bağlıdır (`writableClientProject()`).
        $writable = $project->status !== ProjectStatus::Archived;

        return view('portal.files', compact('project', 'files', 'counts', 'filter', 'stages', 'stage', 'category', 'categories', 'writable'));
    }

    public function download(int $project, int $file)
    {
        $project = $this->clientProject($project);

        // Köçürülmüş köhnə fayl: görünürlüyünü artıq material idarə edir.
        // Köhnə link materialın DƏRC EDİLMİŞ versiyasına yönləndirilir; material
        // dərc edilməyibsə (daxilidir) — 404, birbaşa link də açmır.
        $legacy = $project->files()->whereNotNull('migrated_deliverable_id')->find($file);

        if ($legacy instanceof ProjectFile) {
            return $this->redirectToPublished($project->id, $legacy->migrated_deliverable_id);
        }

        // Fayl id-si URL-dən gəlir, ona görə onu MODELƏ deyil, layihənin öz
        // əlaqəsinə görə həll edirik: yad layihənin və ya daxili faylın id-si
        // buradan 404 ilə qayıdır.
        $file = $project->files()->notMigrated()->clientVisible()->findOrFail($file);

        $ext = pathinfo($file->file_path, PATHINFO_EXTENSION);
        $name = Str::of($file->title ?: 'file')->ascii()->replaceMatches('/[^A-Za-z0-9 _-]/', '')->trim();
        $filename = ($name->isEmpty() ? 'file' : $name).($ext ? '.'.$ext : '');

        abort_unless(self::readableOnPublicDisk($file->file_path), 404, 'Fayl tapılmadı.');

        return Storage::disk('public')->download($file->file_path, $filename);
    }

    /**
     * Köçürülmüş köhnə qeydin (fayl / sənəd) linki → materialın dərc edilmiş
     * versiyasının birinci faylı. `DocumentController` də bunu işlədir.
     */
    public static function redirectToPublished(int $projectId, int $deliverableId): RedirectResponse
    {
        $material = Deliverable::query()
            ->where('project_id', $projectId)
            ->whereNotNull('published_version_id')
            ->findOrFail($deliverableId);

        return redirect()->route('portal.materials.download', [
            'project' => $projectId,
            'material' => $material->id,
            'version' => $material->published_version_id,
            'index' => 0,
        ]);
    }

    /**
     * Köçürülməmiş köhnə fayllar (əgər qalıbsa) — əvvəlki qaydada, sadə sətir
     * kimi. Kateqoriya süzgəcində köhnə kateqoriya material kateqoriyasına
     * çevrilib müqayisə olunur (`DeliverableType::fromFileCategory()`).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function legacyItems($project, ?DeliverableType $category = null): Collection
    {
        $filterOf = collect(self::FILTERS)
            ->flatMap(fn (array $categories, string $key) => collect($categories)->mapWithKeys(fn (FileCategory $c) => [$c->value => $key]))
            ->all();

        return $project->files()->notMigrated()->clientVisible()->latest('id')->get()
            ->filter(fn (ProjectFile $file) => $category === null
                || DeliverableType::fromFileCategory($file->category->value) === $category)
            ->map(fn (ProjectFile $file) => [
                'key' => 'file-'.$file->id,
                'material_id' => null,
                'title' => $file->title,
                'label' => $file->category->label(),
                'icon' => $file->category->value,
                'filter' => $filterOf[$file->category->value] ?? 'files',
                'stage' => null,
                'version' => null,
                'date' => $file->created_at,
                'size' => $file->size,
                'url' => route('portal.files.download', [$project, $file]),
                'files' => [],
                'comments' => [],
                'comment_url' => null,
                'state' => null,
                'pending' => null,
                'pending_version_url' => null,
                'history' => [],
                'sort' => $file->created_at?->timestamp ?? 0,
            ]);
    }

    /**
     * Yol `public` diskində oxunaqlıdırmı — İSTİSNA ATMADAN.
     *
     * Sadə `Storage::disk('public')->exists($path)` kifayət deyil: yol disk
     * kökündən kənara çıxırsa (`../../.env` kimi sətir idxaldan, köhnə
     * məlumatdan və ya təhrif olunmuş qeyddən gələ bilər) Flysystem faylı
     * VERMİR, amma `PathTraversalDetected` atır — həm də məhz `exists()`
     * çağırışının içindən. Yəni yoxlamanın özü 500-ə çevrilir. Müştəriyə
     * sınmış səhifə deyil, təmiz 404 qayıtmalıdır; `APP_DEBUG` açıq mühitdə
     * istisna izi (disk kökü, tətbiq yolları) da sızmamalıdır.
     *
     * Eyni məntiq `DocumentController`, `DiaryController` və `ChatController`-də
     * də var — dördü də `public` diskindən müştəriyə fayl verir.
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
