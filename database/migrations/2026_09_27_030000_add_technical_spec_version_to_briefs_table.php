<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texniki tapşırığın nömrəsi sənədlərin SAYINDAN hesablanırdı: v2 silinən kimi
 * növbəti sənəd yenidən «v2» adlanırdı və tarixçədə eyni nömrəli iki sənəd
 * yaranırdı. Sayğac artıq brifin öz sətrindədir — sənəd silinsə də geri
 * qayıtmır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->unsignedInteger('technical_spec_version')->default(0)->after('current_version');
        });
    }

    public function down(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->dropColumn('technical_spec_version');
        });
    }
};
