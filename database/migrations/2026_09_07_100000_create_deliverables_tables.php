<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ v2.0 §7.11–7.12 — Deliverables & versioning (Project Truth core).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32)->default('custom');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('client_visible')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('deliverable_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverable_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('file_path')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('draft');
            $table->text('change_summary')->nullable();
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['deliverable_id', 'version_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverable_versions');
        Schema::dropIfExists('deliverables');
    }
};
