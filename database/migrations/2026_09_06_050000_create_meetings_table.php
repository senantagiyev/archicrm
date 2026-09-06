<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->json('participants')->nullable();
            $table->string('location')->nullable();
            $table->string('online_link')->nullable();
            $table->text('notes')->nullable();
            $table->string('recording_link')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
