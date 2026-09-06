<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ v2.0 — Əlavə A regression checklist:
 *  #2 EstimateLine: row-level visible_to_client (costs hidden from client by default)
 *  #3 Procurement: reserve_percent, delivery_assembly_price, attachment_url
 *  #4 Files: 5-level visibility model
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->boolean('visible_to_client')->default(false)->after('total');
        });

        Schema::table('procurement_items', function (Blueprint $table) {
            $table->decimal('reserve_percent', 5, 2)->default(0)->after('qty');
            $table->decimal('delivery_assembly_price', 12, 2)->default(0)->after('reserve_percent');
            $table->string('attachment_url')->nullable()->after('url');
        });

        Schema::table('project_files', function (Blueprint $table) {
            $table->string('visibility', 32)->default('internal')->after('category')->index();
        });

        // Migrate any existing rows to the explicit default.
        Schema::table('project_files', function (Blueprint $table) {});
    }

    public function down(): void
    {
        Schema::table('budget_lines', fn (Blueprint $t) => $t->dropColumn('visible_to_client'));
        Schema::table('procurement_items', fn (Blueprint $t) => $t->dropColumn(['reserve_percent', 'delivery_assembly_price', 'attachment_url']));
        Schema::table('project_files', function (Blueprint $t) {
            $t->dropIndex(['visibility']);
            $t->dropColumn('visibility');
        });
    }
};
