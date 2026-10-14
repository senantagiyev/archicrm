<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Razılaşdırmanın müştəriyə qeydi («Nəyə baxmalısınız»).
 *
 * Əvvəl razılaşdırma yalnız smeta sətri, komplektasiya və dizayn materialının
 * düyməsindən yaranırdı və müştəri kartda obyektin adından başqa heç nə
 * görmürdü. Paneldəki «Razılaşdırma yarat» formasında dizayner izah yazır —
 * `comment` sütunu isə müştərinin CAVABIDIR, onu bu məqsədlə işlətmək olmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->text('note')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
