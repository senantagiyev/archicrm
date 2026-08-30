<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('role', 32)->default('designer')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            $table->string('locale', 5)->default('az')->after('is_active');
            $table->string('avatar_path')->nullable()->after('locale');
            $table->softDeletes();

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['phone', 'role', 'is_active', 'locale', 'avatar_path', 'deleted_at']);
        });
    }
};
