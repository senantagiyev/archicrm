<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `roles` and `automation_rules` were one global catalog with a per-studio edit
 * surface: any studio owner could rewrite the access matrix or switch off an
 * automation for EVERY studio on the platform.
 *
 * They stay shared by default (tenant_id = null is the platform-wide row) and
 * gain copy-on-write per studio: editing one from a studio creates that studio's
 * own row, which then shadows the global one. The unique key moves from the bare
 * key/code to (tenant_id, key/code) so both can coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique('roles_key_unique');
            $table->unique(['tenant_id', 'key'], 'roles_tenant_key_unique');
        });

        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique('automation_rules_code_unique');
            $table->unique(['tenant_id', 'code'], 'automation_rules_tenant_code_unique');
        });

        // The dedup ledger is per studio too: two studios hitting the same
        // natural key (invoice #7 in each) silently cancelled one of them.
        //
        // NOT nullable, default 0: SQL treats NULLs as distinct inside a unique
        // index, so a nullable tenant_id would stop de-duplicating entirely for
        // rows with no studio — the exact trap the brief's room_key column hit.
        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->default(0)->after('id');
            $table->dropUnique('automation_runs_rule_code_dedup_key_unique');
            $table->unique(['tenant_id', 'rule_code', 'dedup_key'], 'automation_runs_tenant_rule_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_tenant_key_unique');
            $table->dropConstrainedForeignId('tenant_id');
            $table->unique('key', 'roles_key_unique');
        });

        Schema::table('automation_rules', function (Blueprint $table): void {
            $table->dropUnique('automation_rules_tenant_code_unique');
            $table->dropConstrainedForeignId('tenant_id');
            $table->unique('code', 'automation_rules_code_unique');
        });

        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->dropUnique('automation_runs_tenant_rule_dedup_unique');
            $table->dropColumn('tenant_id');
            $table->unique(['rule_code', 'dedup_key'], 'automation_runs_rule_code_dedup_key_unique');
        });
    }
};
