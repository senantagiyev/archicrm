<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ v2.0 §7.9 / §8.8 — Brief Wizard v2: per-section time estimate + per-question
 * conditional display (skip logic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brief_sections', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimated_minutes')->default(0)->after('icon');
        });

        Schema::table('brief_questions', function (Blueprint $table) {
            // {"question": "<key>", "operator": "equals|not_equals|in", "value": ...}
            $table->json('skip_logic')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('brief_sections', fn (Blueprint $t) => $t->dropColumn('estimated_minutes'));
        Schema::table('brief_questions', fn (Blueprint $t) => $t->dropColumn('skip_logic'));
    }
};
