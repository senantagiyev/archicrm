<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Material kartlarına köçürmənin YOXLAMA SİYAHISI (TZ «Layihə naviqasiyası» §10):
 * təyinatı birmənalı olmayan və ya başqa bölməyə aid olduğu üçün avtomatik
 * köçürülməyən köhnə qeydlər. Heç nə dəyişmir — yalnız oxuyur.
 *
 * Köçürmə: `2026_10_15_200100_migrate_files_and_documents_to_materials`.
 */
class MaterialsLegacyReport extends Command
{
    protected $signature = 'materials:legacy-report';

    protected $description = 'Material kartlarına köçürülməmiş köhnə fayl/sənədləri səbəbi ilə göstərir (yoxlama siyahısı)';

    /** @var array<string, string> sənəd tipi → səbəb */
    private const DOCUMENT_REASONS = [
        'contract' => 'Mühasibatlıq (müqavilə) — köçürülmür',
        'act' => 'Mühasibatlıq (akt) — köçürülmür',
        'brief_export' => 'Brif modulu (brif ixracı) — Brif bölməsində qalır',
        'technical_spec' => 'Brif modulu (texniki tapşırıq) — Brif bölməsində qalır',
        'other' => 'YOXLANMALI: «Digər» tipi — material və ya mühasibat sənədi ola bilər',
    ];

    public function handle(): int
    {
        $files = DB::table('project_files')
            ->whereNull('migrated_deliverable_id')
            ->orderBy('project_id')->orderBy('id')
            ->get(['id', 'project_id', 'title', 'category', 'visibility', 'file_path']);

        $this->line('Köçürülməmiş «Fayllar» sətirləri: '.$files->count());
        if ($files->isNotEmpty()) {
            $this->table(
                ['id', 'layihə', 'ad', 'kateqoriya', 'görünürlük', 'səbəb'],
                $files->map(fn ($f) => [$f->id, $f->project_id, $f->title ?: basename((string) $f->file_path), $f->category, $f->visibility, 'Köçürmədən sonra yaradılıb — köçürməni təkrar işlədin'])->all(),
            );
        }

        $documents = DB::table('documents')
            ->whereNull('migrated_deliverable_id')
            ->orderBy('type')->orderBy('project_id')->orderBy('id')
            ->get(['id', 'project_id', 'type', 'title', 'visible_to_client']);

        $this->line('Köçürülməmiş «Sənədlər» sətirləri: '.$documents->count());
        if ($documents->isNotEmpty()) {
            $this->table(
                ['id', 'layihə', 'tip', 'ad', 'müştəriyə açıq', 'səbəb'],
                $documents->map(fn ($d) => [
                    $d->id, $d->project_id, $d->type, $d->title, $d->visible_to_client ? 'bəli' : 'xeyr',
                    self::DOCUMENT_REASONS[$d->type] ?? 'YOXLANMALI: tanınmayan tip',
                ])->all(),
            );
        }

        $review = $documents->filter(fn ($d) => ! in_array($d->type, ['contract', 'act', 'brief_export', 'technical_spec'], true))->count();
        $this->info("Əl ilə yoxlanmalı: {$review} sənəd.");

        return self::SUCCESS;
    }
}
