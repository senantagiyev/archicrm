<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('telegram', 64)->nullable();
            $table->string('lead_source', 64)->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('new');
            $table->string('estimated_project_type', 64)->nullable();
            $table->decimal('estimated_area', 12, 2)->nullable();
            $table->decimal('estimated_budget', 12, 2)->nullable();
            $table->date('first_contact_date')->nullable();
            $table->date('next_follow_up_date')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('responsible_user_id');
            $table->index('next_follow_up_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
