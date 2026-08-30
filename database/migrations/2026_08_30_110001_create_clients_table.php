<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('telegram', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('source', 32)->nullable();
            $table->string('status', 32)->default('lead');
            $table->date('first_contact_at')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('responsible_user_id');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
