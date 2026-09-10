<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false)->after('is_active');
        });

        $initialAdminId = DB::table('users')
            ->where('role', 'owner')
            ->oldest('id')
            ->value('id');

        if ($initialAdminId !== null) {
            DB::table('users')->where('id', $initialAdminId)->update(['is_platform_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });
    }
};
