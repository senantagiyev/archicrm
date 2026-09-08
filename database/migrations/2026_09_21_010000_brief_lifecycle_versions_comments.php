<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formal brief lifecycle (spec Part 15 MVP): draft → sent → in_progress →
 * submitted → needs_clarification → approved, with immutable BriefVersion
 * snapshots and per-question BriefComment clarification threads.
 * Legacy `completed` briefs become `submitted` and receive a v1 snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('completed_at');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->unsignedInteger('current_version')->default(0)->after('approved_at');
        });

        Schema::create('brief_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brief_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->nullableMorphs('created_by');
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['brief_id', 'version']);
        });

        Schema::create('brief_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brief_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brief_question_id')->constrained('brief_questions')->cascadeOnDelete();
            $table->foreignId('brief_room_id')->nullable()->constrained('brief_rooms')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('status', 16)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['brief_id', 'status']);
        });

        // Legacy status → new lifecycle.
        DB::table('briefs')->where('status', 'completed')->update([
            'status' => 'submitted',
            'submitted_at' => DB::raw('completed_at'),
        ]);

        // v1 snapshot for briefs that were submitted before versioning existed.
        $briefs = DB::table('briefs')->where('status', 'submitted')->where('current_version', 0)->get(['id', 'submitted_at']);

        foreach ($briefs as $brief) {
            $rows = DB::table('brief_answers')
                ->join('brief_questions', 'brief_questions.id', '=', 'brief_answers.brief_question_id')
                ->leftJoin('brief_rooms', 'brief_rooms.id', '=', 'brief_answers.brief_room_id')
                ->where('brief_answers.brief_id', $brief->id)
                ->get(['brief_questions.key', 'brief_answers.brief_room_id', 'brief_rooms.label', 'brief_answers.value', 'brief_answers.delegated_to_designer']);

            $snapshot = ['general' => [], 'rooms' => []];
            foreach ($rows as $row) {
                $entry = ['value' => json_decode($row->value, true), 'delegated' => (bool) $row->delegated_to_designer];
                if ($row->brief_room_id) {
                    $snapshot['rooms'][$row->brief_room_id]['label'] ??= $row->label;
                    $snapshot['rooms'][$row->brief_room_id]['answers'][$row->key] = $entry;
                } else {
                    $snapshot['general'][$row->key] = $entry;
                }
            }

            DB::table('brief_versions')->insert([
                'brief_id' => $brief->id,
                'version' => 1,
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'note' => 'İlkin göndəriş (miqrasiya)',
                'created_at' => $brief->submitted_at ?? now(),
            ]);
            DB::table('briefs')->where('id', $brief->id)->update(['current_version' => 1]);
        }
    }

    public function down(): void
    {
        DB::table('briefs')->whereIn('status', ['submitted', 'needs_clarification', 'approved'])->update(['status' => 'completed']);

        Schema::dropIfExists('brief_comments');
        Schema::dropIfExists('brief_versions');

        Schema::table('briefs', function (Blueprint $table) {
            $table->dropColumn(['submitted_at', 'approved_at', 'current_version']);
        });
    }
};
