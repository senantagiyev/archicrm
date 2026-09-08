<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * brief_answers / brief_section_states are keyed by (brief, question|section,
 * room) — but `brief_room_id` is NULL for every general (non-room) section, and
 * both MySQL and SQLite treat NULLs in a unique index as distinct. That left the
 * general sections effectively unprotected: two autosave requests arriving at
 * once each inserted their own row, and the wizard then read whichever came last.
 *
 * `room_key` mirrors `brief_room_id` with 0 instead of NULL, so the unique index
 * actually holds. With a real constraint in place, Eloquent's firstOrCreate /
 * updateOrCreate resolve the race themselves instead of duplicating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brief_answers', function (Blueprint $table) {
            $table->unsignedBigInteger('room_key')->default(0)->after('brief_room_id');
        });

        Schema::table('brief_section_states', function (Blueprint $table) {
            $table->unsignedBigInteger('room_key')->default(0)->after('brief_room_id');
        });

        DB::table('brief_answers')->update(['room_key' => DB::raw('COALESCE(brief_room_id, 0)')]);
        DB::table('brief_section_states')->update(['room_key' => DB::raw('COALESCE(brief_room_id, 0)')]);

        $this->dedupe('brief_answers', 'brief_question_id');
        $this->dedupe('brief_section_states', 'brief_section_id');

        // The new index is created first: MySQL refuses to drop the old one while
        // it is still the supporting index for the brief_id foreign key.
        Schema::table('brief_answers', function (Blueprint $table) {
            $table->unique(['brief_id', 'brief_question_id', 'room_key'], 'brief_answers_room_key_unique');
        });
        Schema::table('brief_answers', function (Blueprint $table) {
            $table->dropUnique('brief_answers_unique');
        });

        Schema::table('brief_section_states', function (Blueprint $table) {
            $table->unique(['brief_id', 'brief_section_id', 'room_key'], 'brief_section_states_room_key_unique');
        });
        Schema::table('brief_section_states', function (Blueprint $table) {
            $table->dropUnique('brief_section_states_unique');
        });
    }

    /** Keep the oldest row of each duplicated group; the newer ones are the race artefacts. */
    private function dedupe(string $table, string $ownerColumn): void
    {
        $duplicates = DB::table($table)
            ->select('brief_id', $ownerColumn, 'room_key', DB::raw('MIN(id) as keep_id'))
            ->groupBy('brief_id', $ownerColumn, 'room_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $group) {
            DB::table($table)
                ->where('brief_id', $group->brief_id)
                ->where($ownerColumn, $group->{$ownerColumn})
                ->where('room_key', $group->room_key)
                ->where('id', '!=', $group->keep_id)
                ->delete();
        }
    }

    public function down(): void
    {
        Schema::table('brief_answers', function (Blueprint $table) {
            $table->unique(['brief_id', 'brief_question_id', 'brief_room_id'], 'brief_answers_unique');
        });
        Schema::table('brief_answers', function (Blueprint $table) {
            $table->dropUnique('brief_answers_room_key_unique');
            $table->dropColumn('room_key');
        });

        Schema::table('brief_section_states', function (Blueprint $table) {
            $table->unique(['brief_id', 'brief_section_id', 'brief_room_id'], 'brief_section_states_unique');
        });
        Schema::table('brief_section_states', function (Blueprint $table) {
            $table->dropUnique('brief_section_states_room_key_unique');
            $table->dropColumn('room_key');
        });
    }
};
