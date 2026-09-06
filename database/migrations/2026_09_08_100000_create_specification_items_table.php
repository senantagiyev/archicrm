<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ v2.0 §7.18 — SpecificationItem. supplier_cost is F-level sensitive:
 * never serialized to the client (kept out of any client-facing payload).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specification_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32)->default('custom');
            $table->string('room')->nullable();
            $table->string('product_name');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('supplier')->nullable();
            $table->decimal('client_price', 12, 2)->default(0);
            $table->decimal('supplier_cost', 12, 2)->default(0); // hidden from client
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 16)->nullable();
            $table->string('image')->nullable();
            $table->string('link')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('procurement_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specification_items');
    }
};
