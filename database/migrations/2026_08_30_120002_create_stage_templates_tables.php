<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_templates', function (Blueprint $table) {
            $table->id();
            $table->json('name'); // translatable
            $table->string('key', 64)->unique();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('stage_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_template_id')->constrained()->cascadeOnDelete();
            $table->json('name'); // translatable
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('default_duration_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_template_items');
        Schema::dropIfExists('stage_templates');
    }
};
