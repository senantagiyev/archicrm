<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('order_date')->nullable();
            $table->json('items')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('payment_terms')->nullable();
            $table->date('expected_delivery')->nullable();
            $table->string('status', 32)->default('draft');
            $table->softDeletes();
            $table->timestamps();

            $table->index('supplier_id');
            $table->index('project_id');
            $table->index('status');
            $table->index('expected_delivery');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
