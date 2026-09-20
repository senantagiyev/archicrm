<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «İlham nümunələri» bayrağı (B patterni, docs/roomix-brief-ux-analiz.md).
 *
 * Variantın sağındakı kiçik ikon YALNIZ həmin variantın `options[].images`
 * massivi doludursa render olunur — boş modal açan ikon göstərmirik. Bu bayraq
 * isə NİYYƏTİ saxlayır: «bu suala nümunə şəkilləri gözlənilir». Admin paneli
 * ona görə hansı sualların şəkilsiz qaldığını siyahılaya bilir; bayraq
 * olmasaydı, şəkli olmayan sual şəkli olmamalı sualdan seçilməzdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brief_questions', function (Blueprint $table) {
            $table->boolean('supports_inspiration')->default(false)->after('group');
        });
    }

    public function down(): void
    {
        Schema::table('brief_questions', fn (Blueprint $table) => $table->dropColumn('supports_inspiration'));
    }
};
