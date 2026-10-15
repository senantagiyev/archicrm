<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Köhnə «Fayllar» və (maliyyə olmayan) «Sənədlər» sətirləri → material kartları
 * (TZ «Layihə naviqasiyası» §9–10).
 *
 * QAYDALAR:
 *  - İDEMPOTENTDİR: `migrated_deliverable_id` dolu sətir təkrar köçürülmür;
 *    dərc / versiya bərpası yalnız BOŞ sütunları doldurur.
 *  - DAĞIDICI DEYİL: köhnə sətirlər silinmir və dəyişmir (yalnız
 *    `migrated_deliverable_id` yazılır). Fayl diskdə yerində qalır — yeni
 *    versiya EYNİ yola istinad edir, surət yaradılmır.
 *  - Hər köçürülən sətir = 1 material + 1 versiya; müəllif (heyət və ya müştəri),
 *    tarixlər və görünürlük saxlanılır. Müştəriyə açıq idisə, həmin versiya
 *    dərc edilir; daxili / arxiv — daxili qalır.
 *  - Model YOX, query builder: model hook-ları (activity log, tenant, optimistic
 *    lock) köçürmədə iştirak etməsin; `tenant_id` mənbə sətirdən köçürülür.
 *
 * Sənədlərin təyinatı (§9 «digər sənədlər təyinatına görə bölüşdürülməlidir»):
 *  - `drawing` → material («Çertyoj»);
 *  - `contract`, `act` → mühasibatlıq (köçürülmür);
 *  - `brief_export`, `technical_spec` → Brif modulu yaradır və idarə edir
 *    (BriefService, BriefReview) — köçürülmür;
 *  - `other` və tanınmayan tiplər → təyinatı BİRMƏNALI DEYİL (mühasibat sənədi
 *    də ola bilər) — §10: avtomatik bölüşdürülmür, `php artisan
 *    materials:legacy-report` yoxlama siyahısında görünür.
 */
return new class extends Migration
{
    /** Köhnə fayl kateqoriyası → material kateqoriyası (deliverables.type). */
    private const FILE_CATEGORY_MAP = [
        'image' => 'photo',
        'plan' => 'layout',
        'visualization' => 'visualization',
        'link' => 'link',
        'other' => 'custom',
    ];

    /** Müştəri portalında görünən köhnə fayl görünürlükləri. */
    private const CLIENT_VISIBLE_FILE = ['client_shared', 'client_uploaded', 'final'];

    /** Materiala köçürülən sənəd tipləri → material kateqoriyası. */
    private const DOCUMENT_TYPE_MAP = [
        'drawing' => 'drawing',
    ];

    public function up(): void
    {
        $this->backfillApprovalVersions();
        $this->publishClientVisibleDeliverables();

        DB::table('project_files')
            ->whereNull('migrated_deliverable_id')
            ->orderBy('id')
            ->chunkById(200, function ($files): void {
                foreach ($files as $file) {
                    DB::transaction(fn () => $this->migrateFile($file));
                }
            });

        DB::table('documents')
            ->whereNull('migrated_deliverable_id')
            ->whereIn('type', array_keys(self::DOCUMENT_TYPE_MAP))
            ->orderBy('id')
            ->chunkById(200, function ($documents): void {
                foreach ($documents as $document) {
                    DB::transaction(fn () => $this->migrateDocument($document));
                }
            });
    }

    /**
     * Geri qaytarma: köçürmənin yaratdığı və SONRA TOXUNULMAMIŞ materiallar
     * (bir versiya, razılaşdırma və şərh yoxdur) silinir, əlaqə sıfırlanır.
     * Üzərində işlənmiş material saxlanılır — heyətin işi itməsin.
     */
    public function down(): void
    {
        foreach (['project_files', 'documents'] as $table) {
            DB::table($table)->whereNotNull('migrated_deliverable_id')->orderBy('id')
                ->chunkById(200, function ($rows) use ($table): void {
                    foreach ($rows as $row) {
                        $id = (int) $row->migrated_deliverable_id;
                        $versionIds = DB::table('deliverable_versions')->where('deliverable_id', $id)->pluck('id');

                        $untouched = $versionIds->count() <= 1
                            && ! DB::table('approvals')->where('approvable_type', 'deliverable')->where('approvable_id', $id)->exists()
                            && ! DB::table('comments')->where('commentable_type', 'deliverable_version')->whereIn('commentable_id', $versionIds)->exists();

                        if (! $untouched) {
                            continue;
                        }

                        DB::table('deliverable_versions')->where('deliverable_id', $id)->delete();
                        DB::table('deliverables')->where('id', $id)->delete();
                        DB::table($table)->where('id', $row->id)->update(['migrated_deliverable_id' => null]);
                    }
                });
        }
    }

    /**
     * Köhnə material razılaşdırmaları versiyaya bağlanır: sorğu yarandığı anda
     * mövcud olan ən son versiya (yoxdursa — cari versiya).
     */
    private function backfillApprovalVersions(): void
    {
        DB::table('approvals')
            ->where('approvable_type', 'deliverable')
            ->whereNull('deliverable_version_id')
            ->orderBy('id')
            ->chunkById(200, function ($approvals): void {
                foreach ($approvals as $approval) {
                    $versionId = DB::table('deliverable_versions')
                        ->where('deliverable_id', $approval->approvable_id)
                        ->where('created_at', '<=', $approval->created_at)
                        ->orderByDesc('version_number')
                        ->value('id')
                        ?? DB::table('deliverables')->where('id', $approval->approvable_id)->value('current_version_id');

                    if ($versionId) {
                        DB::table('approvals')->where('id', $approval->id)->update(['deliverable_version_id' => $versionId]);
                    }
                }
            });
    }

    /**
     * Mövcud material `client_visible`-dırsa (razılaşdırmaya göndərilib) —
     * müştəriyə GÖNDƏRİLMİŞ versiya dərc edilir, cari versiya yox: sonradan
     * yaradılmış daxili versiya müştəriyə açılmamalıdır.
     */
    private function publishClientVisibleDeliverables(): void
    {
        DB::table('deliverables')
            ->where('client_visible', true)
            ->whereNull('published_version_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($deliverables): void {
                foreach ($deliverables as $deliverable) {
                    $versionId = DB::table('approvals')
                        ->where('approvable_type', 'deliverable')
                        ->where('approvable_id', $deliverable->id)
                        ->whereIn('status', ['pending', 'approved', 'rejected'])
                        ->whereNotNull('deliverable_version_id')
                        ->orderByDesc('id')
                        ->value('deliverable_version_id')
                        ?? $deliverable->current_version_id;

                    if ($versionId) {
                        DB::table('deliverables')->where('id', $deliverable->id)->update(['published_version_id' => $versionId]);
                    }
                }
            });
    }

    private function migrateFile(object $file): void
    {
        $title = filled($file->title) ? $file->title : basename((string) $file->file_path);

        $deliverableId = $this->createMaterial(
            source: $file,
            type: self::FILE_CATEGORY_MAP[$file->category] ?? 'custom',
            title: $title,
            published: in_array($file->visibility, self::CLIENT_VISIBLE_FILE, true),
            summary: 'Köçürülüb: köhnə «Fayllar» #'.$file->id.($file->visibility === 'archive' ? ' (arxiv)' : ''),
        );

        DB::table('project_files')->where('id', $file->id)->update(['migrated_deliverable_id' => $deliverableId]);
    }

    private function migrateDocument(object $document): void
    {
        $deliverableId = $this->createMaterial(
            source: $document,
            type: self::DOCUMENT_TYPE_MAP[$document->type],
            title: $document->title,
            published: (bool) $document->visible_to_client,
            summary: 'Köçürülüb: köhnə «Sənədlər» #'.$document->id,
        );

        DB::table('documents')->where('id', $document->id)->update(['migrated_deliverable_id' => $deliverableId]);
    }

    private function createMaterial(object $source, string $type, string $title, bool $published, string $summary): int
    {
        $deliverableId = (int) DB::table('deliverables')->insertGetId([
            'tenant_id' => $source->tenant_id ?? null,
            'project_id' => $source->project_id,
            'stage_id' => null,
            'type' => $type,
            'title' => mb_substr($title, 0, 255),
            'status' => 'draft',
            'client_visible' => $published,
            'created_at' => $source->created_at,
            'updated_at' => $source->updated_at,
        ]);

        $extension = pathinfo((string) $source->file_path, PATHINFO_EXTENSION);

        $versionId = (int) DB::table('deliverable_versions')->insertGetId([
            'tenant_id' => $source->tenant_id ?? null,
            'deliverable_id' => $deliverableId,
            'version_number' => 1,
            'file_path' => $source->file_path,
            'attachments' => json_encode([[
                'path' => $source->file_path,
                'name' => $title.($extension !== '' && ! str_ends_with(mb_strtolower($title), '.'.mb_strtolower($extension)) ? '.'.$extension : ''),
                'size' => $source->size !== null ? (int) $source->size : null,
                'mime' => $source->mime,
            ]], JSON_UNESCAPED_UNICODE),
            'created_by_user_id' => $source->uploaded_by_type === 'user' ? $source->uploaded_by_id : null,
            'uploaded_by_type' => $source->uploaded_by_type,
            'uploaded_by_id' => $source->uploaded_by_id,
            'status' => 'draft',
            'change_summary' => $summary,
            'created_at' => $source->created_at,
            'updated_at' => $source->updated_at,
        ]);

        DB::table('deliverables')->where('id', $deliverableId)->update([
            'current_version_id' => $versionId,
            'published_version_id' => $published ? $versionId : null,
        ]);

        return $deliverableId;
    }
};
