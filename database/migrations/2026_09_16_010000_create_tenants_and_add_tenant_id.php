<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational tables isolated per tenant. Shared catalogs (roles,
     * automation_rules, brief_*, stage_templates, translations, settings) stay
     * global and are intentionally omitted.
     */
    private array $tables = [
        'users', 'clients', 'client_users', 'leads', 'suppliers', 'projects',
        'purchase_orders', 'invoices', 'expenses', 'meetings', 'payments',
        'budget_lines', 'procurement_items', 'documents', 'project_files',
        'deliverables', 'deliverable_versions', 'specification_items',
        'change_requests', 'time_entries', 'project_decisions', 'punch_list_issues',
        'approvals', 'tasks', 'stages', 'briefs',
    ];

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Default tenant — every existing row belongs to it.
        $defaultId = DB::table('tenants')->insertGetId([
            'name' => 'ARCHI Studio',
            'slug' => 'archi',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
                    $t->index('tenant_id');
                });

                DB::table($table)->update(['tenant_id' => $defaultId]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('tenant_id');
                });
            }
        }

        Schema::dropIfExists('tenants');
    }
};
