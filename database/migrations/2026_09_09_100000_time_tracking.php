<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ v2.0 §7.27 — Time tracking + team capacity inputs on users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('hourly_internal_cost', 10, 2)->default(0)->after('role');
            $table->unsignedSmallInteger('weekly_capacity_hours')->default(40)->after('hourly_internal_cost');
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->decimal('hourly_cost_snapshot', 10, 2)->default(0);
            $table->string('comment')->nullable();
            $table->string('source', 16)->default('manual');
            $table->timestamps();

            $table->index(['user_id', 'project_id']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['hourly_internal_cost', 'weekly_capacity_hours']));
    }
};
