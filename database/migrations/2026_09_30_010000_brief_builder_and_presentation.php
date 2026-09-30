<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brif konstruktoru və «təqdim edilmiş brif» anlayışı.
 *
 * 1. `brief_templates.tenant_id` — studiyanın ÖZ hazırladığı brif. `null`
 *    platforma şablonudur (git-dəki bank: Quick / Yaşayış / Kommersiya) və
 *    hamıya görünür; dəyər varsa şablon yalnız o studiyaya aiddir və yalnız o
 *    studiya redaktə edə bilər. Cədvəl QƏSDƏN `BelongsToTenant` işlətmir —
 *    qlobal scope sistem şablonlarını gizlədərdi.
 *
 * 2. `briefs.presented_at` — brifin müştəriyə RƏSMƏN göstərildiyi an. Əvvəl
 *    brif layihə ilə birlikdə öz-özünə yaranır və portalda dərhal görünürdü.
 *    İndi studiya brifi hazırlayır (və ya sistem şablonunu seçir) və özü
 *    «göndər» deyir; ona qədər müştəri heç bir brif görmür. Statusdan ayrı
 *    sütundur, çünki status cavabların gedişi ilə dəyişir (`sent` →
 *    `in_progress` → cavablar silinsə yenə `draft`) və təqdimat faktını
 *    daşıya bilməz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brief_templates', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->after('tenant_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('briefs', function (Blueprint $table) {
            $table->timestamp('presented_at')->nullable()->after('status');
        });

        // Mövcud briflər artıq portalda görünürdü — onları gizlətmək müştərinin
        // yarımçıq cavablarını «itirmək» kimi görünərdi. Hamısı təqdim edilmiş
        // sayılır; qayda yalnız bundan sonra yaranan briflərə tətbiq olunur.
        DB::table('briefs')->whereNull('presented_at')->update(['presented_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('briefs', fn (Blueprint $table) => $table->dropColumn('presented_at'));

        Schema::table('brief_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
