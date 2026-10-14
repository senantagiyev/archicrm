<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diary_entries', function (Blueprint $table) {
            // Mövcud qeydlərin icra vəziyyəti məlum deyil — hamısı «Yeni» kimi
            // başlayır, dizayner lazım olanı sonra «İcra edildi»yə keçirir.
            // Sütun NOT NULL + defolt: köhnə sətirlər də enum cast-dan keçə bilsin.
            $table->string('status', 32)->default('new')->after('body');

            // Panel süzgəci: layihə daxilində status üzrə.
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('diary_entries', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
