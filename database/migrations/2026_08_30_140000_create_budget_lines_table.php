<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Smeta: work + material budget lines (as-is Roomix model + approval status).
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained()->nullOnDelete();
            $table->string('work_type');
            $table->string('room')->nullable();
            $table->string('unit', 16)->nullable();
            $table->decimal('qty', 10, 2)->default(1);
            $table->decimal('work_price', 12, 2)->default(0);
            $table->decimal('material_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('approval_status', 32)->default('draft');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
