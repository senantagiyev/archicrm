<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->date('date_plan_start')->nullable();
            $table->date('date_plan_end')->nullable();
            $table->date('date_fact_start')->nullable();
            $table->date('date_fact_end')->nullable();
            $table->string('status', 32)->default('not_started');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Cached: share of done tasks (ReadinessService).
            $table->unsignedTinyInteger('readiness')->default(0);
            // Weight in the project readiness weighted average.
            $table->unsignedTinyInteger('weight')->default(1);
            $table->timestamps();

            $table->index(['project_id', 'position']);
            $table->index(['status', 'date_plan_end']); // overdue scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
    }
};
