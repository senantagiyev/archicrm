<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('photo_path')->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('room')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('qty', 10, 2)->default(1);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('store')->nullable();
            $table->string('url')->nullable();
            $table->string('approval_status', 32)->default('draft');
            $table->string('purchase_status', 32)->default('planned');
            $table->text('cancel_comment')->nullable();
            $table->boolean('paid')->default(false);
            $table->timestamps();

            $table->index(['project_id', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_items');
    }
};
