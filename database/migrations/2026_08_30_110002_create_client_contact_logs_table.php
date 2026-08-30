<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contact_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32)->default('note');
            $table->text('note');
            $table->timestamp('contacted_at');
            $table->timestamps();

            $table->index(['client_id', 'contacted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contact_logs');
    }
};
