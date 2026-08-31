<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->morphs('author'); // user | client_user
            $table->text('body');
            $table->timestamps();

            $table->index(['project_id', 'id']);
        });

        Schema::create('chat_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->morphs('participant');
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'participant_type', 'participant_id'], 'chat_reads_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reads');
        Schema::dropIfExists('chat_messages');
    }
};
