<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->default('other');
            $table->string('title');
            $table->string('file_path');
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime', 128)->nullable();
            $table->nullableMorphs('uploaded_by');
            $table->boolean('visible_to_client')->default(false);
            $table->timestamps();

            $table->index(['project_id', 'type']);
        });

        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32)->default('other');
            $table->string('title')->nullable();
            $table->string('file_path');
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime', 128)->nullable();
            $table->nullableMorphs('uploaded_by');
            $table->timestamps();

            $table->index(['project_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_files');
        Schema::dropIfExists('documents');
    }
};
