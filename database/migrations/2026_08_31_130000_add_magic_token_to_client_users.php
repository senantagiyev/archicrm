<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            // Hashed one-time token for the current magic link; nulled on use so a
            // link cannot be replayed within its validity window (audit MEDIUM-2).
            $table->string('magic_token', 64)->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            $table->dropColumn('magic_token');
        });
    }
};
