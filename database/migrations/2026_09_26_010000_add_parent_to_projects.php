<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roomix «Subprojects»: layihənin təmir/tikinti mərhələsi üçün ayrıca məkan —
 * öz çatı, mərhələləri, faylları və iştirakçıları (podratçılar daxil).
 *
 * Ayrıca cədvəl QURULMUR: alt-layihə də layihədir (eyni mərhələlər, fayllar,
 * razılaşdırmalar lazımdır), sadəcə valideyni var. Ayrı model qursaydıq,
 * mövcud bütün modullar iki dəfə yazılmalı olardı.
 *
 * `nullOnDelete` (kaskad YOX): kaskad silmə alt-layihə ilə birlikdə müştərinin
 * çatını və sənədlərini də aparardı.
 *
 * DİQQƏT: `Project` soft-delete edir, ona görə adi silmədə bu qayda İŞLƏMİR —
 * sətir yerində qalır, `parent` münasibəti isə silinmiş valideyni qaytarmadığı
 * üçün sadəcə `null` olur. Qayda yalnız bazadan həqiqi silmə (force delete və
 * ya təmizləmə skripti) halında qoruyucu kimi işə düşür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('parent_project_id')
                ->nullable()
                ->after('client_id')
                ->constrained('projects')
                ->nullOnDelete();

            // Valideynin alt-layihələrini çəkmək layihə siyahısında hər sətir
            // üçün baş verir, ona görə indeks.
            $table->index('parent_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['parent_project_id']);
            $table->dropIndex(['parent_project_id']);
            $table->dropColumn('parent_project_id');
        });
    }
};
