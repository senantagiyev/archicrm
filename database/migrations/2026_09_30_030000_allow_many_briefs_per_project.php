<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bir layihədə bir neçə brif.
 *
 * Əvvəl `briefs.project_id` unikal idi: layihəyə ikinci brif göndərmək yeni brif
 * yaratmırdı, mövcudunun şablonunu dəyişirdi — müştəri üç göndərişdən yalnız
 * sonuncunu görürdü, üstəlik əvvəlki brif artıq göndərilmişdisə yeni şablon
 * kilidli açılırdı.
 *
 * İndi unikallıq `(project_id, brief_template_id)` cütündədir: layihədə
 * istənilən sayda brif, amma bir şablondan yalnız biri. Bu, portalda bölmə
 * ünvanından (`/brief/{section}`) brifi birmənalı tapmağa imkan verir —
 * bölmə şablona, şablon isə layihədə tək brifə aiddir.
 *
 * Sıra vacibdir (MySQL): köhnə unikal indeks `project_id` xarici açarının
 * indeksidir; əvvəl eyni sütunla başlayan yeni indeks yaradılır, sonra köhnəsi
 * silinir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->unique(['project_id', 'brief_template_id'], 'briefs_project_template_unique');
        });

        Schema::table('briefs', function (Blueprint $table) {
            $table->dropUnique('briefs_project_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->unique('project_id', 'briefs_project_id_unique');
        });

        Schema::table('briefs', function (Blueprint $table) {
            $table->dropUnique('briefs_project_template_unique');
        });
    }
};
