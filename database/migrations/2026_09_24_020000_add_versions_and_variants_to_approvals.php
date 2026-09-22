<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roomix-in razılaşdırma kartı: «Bedroom: two options · v2 · Waiting for
 * approval from Kate · Client thinking for 15 days».
 *
 * Üç şey əlavə olunur:
 *  - `version` — dizayner düzəlişdən sonra yenidən göndərəndə nömrə artır;
 *    sətirlər onsuz da hər göndərişdə yenidən yaranır (`ApprovalService::request()`
 *    köhnəni `draft`-a keçirir), yəni tarixçə var idi, amma nömrəsi yox idi.
 *  - `variants` + `chosen_variant` — müştəriyə bir neçə variant göndərib
 *    birini seçdirmək. Əvvəl razılaşdırma yalnız «bəli/xeyr» idi.
 *  - layihədə `client_response_days` — Roomix-dəki «Client response window»,
 *    `respond_by` üçün standart müddət.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1)->after('status');
            $table->json('variants')->nullable()->after('comment');
            $table->string('chosen_variant', 64)->nullable()->after('variants');
        });

        Schema::table('projects', function (Blueprint $table) {
            // Roomix-dəki siyahı: 1 · 2 · 3 · 5 · 7 · 10 · 14 gün, standart 3.
            $table->unsignedTinyInteger('client_response_days')->default(3)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn(['version', 'variants', 'chosen_variant']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('client_response_days');
        });
    }
};
