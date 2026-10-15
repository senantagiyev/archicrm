<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Fayllar» = material kartları (TZ «Layihə naviqasiyası» §5–6, §10).
 *
 * Material kartı mövcud `Deliverable` + `DeliverableVersion` üzərində qurulur.
 * KATEQORİYA ayrıca sütun DEYİL — `deliverables.type` (DeliverableType) elə
 * materialın növüdür; enum planlar/foto/sənəd/keçid ilə genişləndirilib.
 *
 *  - `deliverables.published_version_id`: müştəri YALNIZ bu versiyanı görür.
 *    Yeni daxili versiya onu avtomatik əvəz etmir; dərc ≠ təsdiq.
 *  - `deliverable_versions.attachments`: bir versiyada bir neçə əlaqəli fayl
 *    (`file_path` birincisini təkrarlayır — köhnə oxucular sınmasın).
 *  - `deliverable_versions.uploaded_by_*`: müəllif həm heyət, həm müştəri ola
 *    bilər (köçürülən «Müştəri yükləyib» faylları).
 *  - `comments.is_internal`: daxili şərh müştəriyə heç yerdə çıxmır. Defolt
 *    TRUE-dur (səhvən açılmasın); müştərinin yazdığı köhnə şərhlər açıqdır.
 *  - `approvals.deliverable_version_id`: razılaşdırma konkret VERSİYA üzrədir —
 *    yeni versiya köhnənin təsdiqini miras almır.
 *  - `stages.default_categories` / `stage_template_items.default_categories`:
 *    kateqoriya → layihə daxilində standart mərhələ.
 *  - `project_files` / `documents`.`migrated_deliverable_id`: köhnə sətrin
 *    köçürüldüyü material (köhnə linklər yönləndirilir, sətir SİLİNMİR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliverables', function (Blueprint $table) {
            $table->unsignedBigInteger('published_version_id')->nullable()->after('current_version_id')->index();
        });

        Schema::table('deliverable_versions', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('file_path');
            $table->nullableMorphs('uploaded_by');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->boolean('is_internal')->default(true)->after('body');
        });

        // Müştərinin özünün yazdığı şərh ona daxili ola bilməz.
        DB::table('comments')->where('author_type', 'client_user')->update(['is_internal' => false]);

        Schema::table('approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('deliverable_version_id')->nullable()->after('approvable_id')->index();
        });

        Schema::table('stages', function (Blueprint $table) {
            $table->json('default_categories')->nullable();
        });

        Schema::table('stage_template_items', function (Blueprint $table) {
            $table->json('default_categories')->nullable();
        });

        Schema::table('project_files', function (Blueprint $table) {
            $table->unsignedBigInteger('migrated_deliverable_id')->nullable()->index();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('migrated_deliverable_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['migrated_deliverable_id']);
            $table->dropColumn('migrated_deliverable_id');
        });

        Schema::table('project_files', function (Blueprint $table) {
            $table->dropIndex(['migrated_deliverable_id']);
            $table->dropColumn('migrated_deliverable_id');
        });

        Schema::table('stage_template_items', fn (Blueprint $table) => $table->dropColumn('default_categories'));
        Schema::table('stages', fn (Blueprint $table) => $table->dropColumn('default_categories'));

        Schema::table('approvals', function (Blueprint $table) {
            $table->dropIndex(['deliverable_version_id']);
            $table->dropColumn('deliverable_version_id');
        });

        Schema::table('comments', fn (Blueprint $table) => $table->dropColumn('is_internal'));

        Schema::table('deliverable_versions', function (Blueprint $table) {
            $table->dropMorphs('uploaded_by');
            $table->dropColumn('attachments');
        });

        Schema::table('deliverables', function (Blueprint $table) {
            $table->dropIndex(['published_version_id']);
            $table->dropColumn('published_version_id');
        });
    }
};
