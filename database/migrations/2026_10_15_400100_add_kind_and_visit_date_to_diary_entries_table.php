<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müəllif nəzarəti → Səfərlər və hesabatlar (TZ §8).
 *
 * Gündəlik qeydi artıq ya obyektə SƏFƏR, ya da HESABAT-dır; səfərin öz tarixi
 * var (qeydin yazıldığı və ya dərc olunduğu tarixdən fərqli ola bilər).
 * Mövcud qeydlər «səfər» sayılır, tarixi boş qalır — uydurulmur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diary_entries', function (Blueprint $table) {
            $table->string('kind', 16)->default('visit')->after('project_id');
            $table->date('visited_on')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('diary_entries', function (Blueprint $table) {
            $table->dropColumn(['kind', 'visited_on']);
        });
    }
};
