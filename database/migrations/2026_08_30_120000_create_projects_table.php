<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('address')->nullable();
            $table->decimal('area', 8, 2)->nullable();
            $table->decimal('budget_plan', 12, 2)->nullable();
            $table->decimal('budget_fact', 12, 2)->nullable();
            $table->date('deadline')->nullable();
            $table->string('status', 32)->default('active');
            // Cached aggregates, recalculated by ReadinessService / ProjectFinanceService.
            $table->unsignedTinyInteger('readiness')->default(0);
            $table->decimal('debt', 12, 2)->default(0);
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('client_id');
            $table->index('status');
            $table->index('deadline');
            $table->index('manager_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
