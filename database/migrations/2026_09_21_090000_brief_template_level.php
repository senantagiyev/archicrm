<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brif spesifikasiyası Part 8.1 — brifin iki səviyyəsi: «Quick Brief» (~3 dəq,
 * işin əvvəlində, müqavilədən əvvəl) və «Premium Brief» (~25 dəq, konsepsiyanın
 * startından əvvəl). Hər ikisi şablondur; `level` onları bir-birindən ayırır ki,
 * yeni brif heç vaxt təsadüfən qısa anketlə açılmasın.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brief_templates', function (Blueprint $table) {
            $table->string('level', 16)->default('premium')->after('key');
        });
    }

    public function down(): void
    {
        Schema::table('brief_templates', fn (Blueprint $table) => $table->dropColumn('level'));
    }
};
