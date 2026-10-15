<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Müəllif nəzarəti → İradlar (TZ §8, ssenari 3).
 *
 *  • `photos` / `resolved_photos` — bir neçə foto (telefondan çəkilmiş);
 *    köhnə tək `photo_url` / `resolved_photo_url` dəyərləri massivə köçürülür,
 *    köhnə sütunlar SİLİNMİR (məlumat itmir, geri qayıtmaq mümkündür);
 *  • `resolution_note`, `resolved_at`, `resolved_by_user_id` — məsul şəxsin
 *    düzəlişin nəticəsini qeyd etməsi;
 *  • `closed_at`, `closed_by_user_id` — yoxlayanın iradı bağlaması;
 *  • `created_by_user_id` — iradı kim qeydə aldı (bağlamanı o yoxlayır);
 *  • `diary_entry_id` — irad hansı səfərdə / hesabatda aşkar edilib
 *    (səfərin fotoları yenidən yüklənmədən irada keçir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('punch_list_issues', function (Blueprint $table) {
            $table->json('photos')->nullable()->after('photo_url');
            $table->json('resolved_photos')->nullable()->after('resolved_photo_url');
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('diary_entry_id')->nullable()->constrained('diary_entries')->nullOnDelete();
        });

        // Köhnə tək fotoları yeni massivlərə köçür. Sətir-sətir: JSON
        // funksiyaları MySQL və SQLite-da fərqlidir.
        DB::table('punch_list_issues')
            ->where(fn ($query) => $query->whereNotNull('photo_url')->orWhereNotNull('resolved_photo_url'))
            ->orderBy('id')
            ->each(function (object $row): void {
                DB::table('punch_list_issues')->where('id', $row->id)->update([
                    'photos' => filled($row->photo_url) ? json_encode([$row->photo_url]) : null,
                    'resolved_photos' => filled($row->resolved_photo_url) ? json_encode([$row->resolved_photo_url]) : null,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('punch_list_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diary_entry_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('closed_by_user_id');
            $table->dropColumn('closed_at');
            $table->dropConstrainedForeignId('resolved_by_user_id');
            $table->dropColumn(['resolved_at', 'resolution_note', 'resolved_photos', 'photos']);
        });
    }
};
