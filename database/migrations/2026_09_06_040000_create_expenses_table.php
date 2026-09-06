<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 32);
            $table->string('vendor')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('AZN');
            $table->date('date');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('project_id');
            $table->index('category');
            $table->index('status');
            $table->index('date');
            $table->index('created_by_user_id');
            $table->index('approved_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
