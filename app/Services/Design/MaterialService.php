<?php

namespace App\Services\Design;

use App\Enums\DeliverableStatus;
use App\Enums\DeliverableType;
use App\Enums\DeliverableVersionStatus;
use App\Models\Approval;
use App\Models\Comment;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\Project;
use App\Models\Stage;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * «Fayllar» bölməsinin material kartları (TZ «Layihə naviqasiyası» §5–6).
 *
 * Material = `Deliverable`, versiya = `DeliverableVersion`. Üç ayrı əməliyyat:
 *  - DƏRC: müştəri yalnız `published_version_id` versiyanı görür; yeni daxili
 *    versiya onu avtomatik əvəz etmir;
 *  - RAZILAŞDIRMA: konkret versiya üzrə `ApprovalService::request()`; yeni
 *    versiya köhnənin təsdiqini miras almır; mərhələni tamamlamır;
 *  - ŞƏRH: konkret versiyaya; daxili şərh müştəriyə heç yerdə çıxmır.
 */
class MaterialService
{
    public const VISIBILITY_INTERNAL = 'internal';

    public const VISIBILITY_PUBLISHED = 'published';

    public const DISK = 'public';

    public const DIRECTORY = 'materials';

    public function __construct(
        private DeliverableService $deliverables,
        private ApprovalService $approvals,
    ) {}

    /**
     * Yeni material kartı + onun ilk versiyası.
     *
     * @param  array{title: string, type?: string|null, stage_id?: int|string|null, visibility?: string|null, files: array<int, string>|string, file_names?: array<string, string>|null, description?: string|null, responsible_user_id?: int|string|null, change_summary?: string|null}  $data
     */
    public function create(Project $project, array $data, User $by): Deliverable
    {
        $files = $this->attachments($data['files'] ?? [], $data['file_names'] ?? []);

        if ($files === []) {
            throw new InvalidArgumentException('Ən azı bir fayl yüklənməlidir.');
        }

        $type = DeliverableType::tryFrom((string) ($data['type'] ?? '')) ?? DeliverableType::Custom;

        return DB::transaction(function () use ($project, $data, $by, $files, $type): Deliverable {
            $material = $project->deliverables()->create([
                'title' => trim((string) $data['title']),
                'type' => $type,
                'stage_id' => $this->stageOf($project, $data['stage_id'] ?? null)?->id,
                'description' => $data['description'] ?? null,
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
                'status' => DeliverableStatus::Draft,
            ]);

            $version = $this->deliverables->createVersion($material, null, $by, $data['change_summary'] ?? null, $files);

            if (($data['visibility'] ?? self::VISIBILITY_INTERNAL) === self::VISIBILITY_PUBLISHED) {
                $this->publish($material->refresh(), $version);
            }

            return $material->refresh();
        });
    }

    /**
     * Mövcud karta yeni versiya. Əvvəlki versiyalar, onların şərhləri və
     * qərarları saxlanılır. Dərc edilmiş versiya yalnız `$publish` açıq
     * istənəndə dəyişir — əks halda müştəri köhnə versiyanı görməyə davam edir.
     *
     * @param  array<int, string>|string  $paths
     * @param  array<string, string>  $names
     */
    public function addVersion(Deliverable $material, array|string $paths, array $names, User $by, ?string $summary = null, bool $publish = false): DeliverableVersion
    {
        $files = $this->attachments($paths, $names);

        if ($files === []) {
            throw new InvalidArgumentException('Ən azı bir fayl yüklənməlidir.');
        }

        return DB::transaction(function () use ($material, $files, $by, $summary, $publish): DeliverableVersion {
            $version = $this->deliverables->createVersion($material, null, $by, $summary, $files);

            if ($publish) {
                $this->publish($material->refresh(), $version);
            }

            return $version;
        });
    }

    /** Versiyanı müştəriyə dərc edir. Dərc ≠ təsdiq — razılaşdırma yaranmır. */
    public function publish(Deliverable $material, DeliverableVersion $version): void
    {
        $this->assertVersionOf($material, $version);

        $material->forceFill(['published_version_id' => $version->id])->save();
    }

    /** Materialı müştəridən gizlədir (heç bir versiyası görünmür). */
    public function unpublish(Deliverable $material): void
    {
        $material->forceFill(['published_version_id' => null])->save();
    }

    /**
     * Versiyanı razılaşdırmaya göndərir. Müştəri razılaşdırdığı versiyanı
     * görməlidir, ona görə həmin versiya dərc edilir (əgər deyilsə).
     * Cavab müddəti verilməyibsə layihənin «müştərinin cavab müddəti» tətbiq
     * olunur (`ApprovalService::request()`).
     */
    public function sendForApproval(Deliverable $material, DeliverableVersion $version, User $by, ?Carbon $respondBy = null, ?string $note = null): Approval
    {
        $this->assertVersionOf($material, $version);

        if ($version->status->isImmutable() || $version->status === DeliverableVersionStatus::Superseded) {
            throw new InvalidArgumentException('Bu versiya razılaşdırmaya göndərilə bilməz: «'.$version->status->label().'». Yeni versiya yaradın.');
        }

        return DB::transaction(function () use ($material, $version, $by, $respondBy, $note): Approval {
            if ((int) $material->published_version_id !== (int) $version->id) {
                $this->publish($material, $version);
            }

            return $this->approvals->request($material->refresh(), $by, $respondBy, [], $note, $version);
        });
    }

    /** Konkret versiyaya şərh. Defolt daxilidir. */
    public function addComment(DeliverableVersion $version, Model $author, string $body, bool $internal = true): Comment
    {
        $body = trim($body);

        if ($body === '') {
            throw new InvalidArgumentException('Şərh boş ola bilməz.');
        }

        return $version->comments()->create([
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->getKey(),
            'body' => $body,
            'is_internal' => $internal,
        ]);
    }

    /**
     * Kateqoriya → layihə daxilində standart mərhələ (`stages.default_categories`,
     * şablondan köçürülür). Bir neçə mərhələ uyğun gəlirsə, sırada birincisi.
     */
    public function defaultStageFor(Project $project, ?string $category): ?Stage
    {
        if (blank($category)) {
            return null;
        }

        return $project->stages()
            ->orderBy('position')
            ->get()
            ->first(fn (Stage $stage) => in_array($category, (array) ($stage->default_categories ?? []), true));
    }

    /** Mərhələ kontekstindən məlum olan kateqoriya (mərhələnin birinci standart kateqoriyası). */
    public function defaultCategoryFor(?Stage $stage): ?string
    {
        $first = collect((array) ($stage?->default_categories ?? []))->first();

        return DeliverableType::tryFrom((string) $first)?->value;
    }

    /** Mərhələ yalnız HƏMİN layihədən qəbul edilir; yad id səssizcə «ümumi» olmur, xəta verir. */
    public function stageOf(Project $project, int|string|null $stageId): ?Stage
    {
        if (blank($stageId)) {
            return null;
        }

        $stage = $project->stages()->find($stageId);

        if (! $stage) {
            throw new InvalidArgumentException('Mərhələ bu layihəyə aid deyil.');
        }

        return $stage;
    }

    /**
     * Yüklənmiş yollar → versiyanın `attachments` massivi. Ölçü/MIME diskdən
     * oxunur; ad — istifadəçinin faylının orijinal adı.
     *
     * @param  array<int, string>|string  $paths
     * @param  array<string, string>|null  $names
     * @return array<int, array{path: string, name: string, size: int|null, mime: string|null}>
     */
    public function attachments(array|string $paths, ?array $names = []): array
    {
        $disk = Storage::disk(self::DISK);

        return collect(is_array($paths) ? $paths : [$paths])
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values()
            ->map(function (string $path) use ($disk, $names): array {
                try {
                    $size = $disk->exists($path) ? $disk->size($path) : null;
                    $mime = $disk->exists($path) ? $disk->mimeType($path) : null;
                } catch (Throwable) {
                    $size = null;
                    $mime = null;
                }

                return [
                    'path' => $path,
                    'name' => (string) ($names[$path] ?? basename($path)),
                    'size' => $size,
                    'mime' => $mime ?: null,
                ];
            })
            ->all();
    }

    private function assertVersionOf(Deliverable $material, DeliverableVersion $version): void
    {
        if ((int) $version->deliverable_id !== (int) $material->id) {
            throw new InvalidArgumentException('Versiya bu materiala aid deyil.');
        }
    }
}
