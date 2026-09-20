<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sual qrupu — bölmə daxilində alt başlıq (Roomix: «СТИЛЬ», «ЦВЕТ», «ШТОРЫ»).
 *
 * Uzun bölmələr (Örtük materialları 30+, İşıqlandırma 20+ sual) indiyə qədər
 * qrupsuz düz siyahı idi və istifadəçi harada olduğunu itirirdi. Qrup YALNIZ
 * vizual çeşidləmədir: cavablara, şərti məntiqə və PDF-ə təsir etmir, ona görə
 * nullable-dır və köhnə suallar toxunulmadan işləməyə davam edir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brief_questions', function (Blueprint $table) {
            $table->string('group', 64)->nullable()->after('help');
        });
    }

    public function down(): void
    {
        Schema::table('brief_questions', fn (Blueprint $table) => $table->dropColumn('group'));
    }
};
