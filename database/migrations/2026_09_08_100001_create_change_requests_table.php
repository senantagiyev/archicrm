<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ v2.0 §7.15 — ChangeRequest. Approved objects can't be edited directly;
 * a change goes through this tracked, impact-assessed flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('number')->nullable();
            $table->string('requested_by', 16)->default('team'); // client | team
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('reason')->nullable();
            $table->string('affected_entity_type')->nullable();
            $table->unsignedBigInteger('affected_entity_id')->nullable();
            // Impact Assessment (manual in MVP)
            $table->integer('schedule_impact_days')->default(0);
            $table->decimal('cost_impact', 12, 2)->default(0);
            $table->decimal('estimated_hours', 8, 2)->default(0);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};
