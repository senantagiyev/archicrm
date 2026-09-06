<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalog of automations (TZ §8.21 / Əlavə B). Rows are seeded by `code`
        // and toggled from Admin → Avtomatlaşdırmalar without a deploy.
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('trigger');
            $table->string('priority')->default('medium');
            $table->boolean('enabled')->default(true)->index();
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->timestamps();

            $table->index('trigger');
        });

        // Idempotency ledger for triggers that can re-fire from external events or
        // repeated scheduler ticks (Əlavə B guards on rules 5, 6, 29 + all reminders).
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('rule_code');
            $table->string('dedup_key');
            $table->timestamp('created_at')->nullable();

            $table->unique(['rule_code', 'dedup_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
