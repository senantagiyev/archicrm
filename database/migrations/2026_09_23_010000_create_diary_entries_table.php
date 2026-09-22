<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_entries', function (Blueprint $table) {
            $table->id();
            // Digər əməliyyat cədvəlləri kimi kirayəçi üzrə izolyasiya olunur.
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Müəllif studiya işçisidir; hesab silinsə qeyd itməməlidir.
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->json('photos')->nullable();
            // null = qaralama; portal yalnız dərc olunmuşları göstərir.
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            // Portal sorğusu: layihə üzrə, dərc tarixinə görə azalan.
            $table->index(['project_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_entries');
    }
};
