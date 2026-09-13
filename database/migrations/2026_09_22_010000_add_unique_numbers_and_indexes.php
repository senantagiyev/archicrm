<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document numbers were unique only by convention — nothing stopped two
 * accountants issuing the same invoice number, and change-request numbering
 * re-used a number after a deletion. Also adds the composite indexes every
 * tenant-scoped list query actually filters on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->deduplicateInvoiceNumbers();

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'number'], 'invoices_tenant_number_unique');
        });

        Schema::table('change_requests', function (Blueprint $table): void {
            $table->unique(['project_id', 'number'], 'change_requests_project_number_unique');
        });

        // tenant_id led no composite index, so every scoped list filtered on one
        // column and filesorted the rest.
        foreach ([
            'projects' => ['tenant_id', 'status'],
            'tasks' => ['tenant_id', 'status'],
            'payments' => ['tenant_id', 'status'],
            'clients' => ['tenant_id', 'status'],
        ] as $table => $columns) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) use ($columns, $table): void {
                    $t->index($columns, $table.'_tenant_status_index');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_tenant_number_unique');
        });

        Schema::table('change_requests', function (Blueprint $table): void {
            $table->dropUnique('change_requests_project_number_unique');
        });

        foreach (['projects', 'tasks', 'payments', 'clients'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $t) use ($table): void {
                    $t->dropIndex($table.'_tenant_status_index');
                });
            }
        }
    }

    /** Existing duplicates would block the unique index; suffix them instead. */
    private function deduplicateInvoiceNumbers(): void
    {
        $duplicates = DB::table('invoices')
            ->select('tenant_id', 'number')
            ->groupBy('tenant_id', 'number')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $ids = DB::table('invoices')
                ->where('tenant_id', $duplicate->tenant_id)
                ->where('number', $duplicate->number)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1);

            foreach ($ids as $offset => $id) {
                DB::table('invoices')->where('id', $id)->update([
                    'number' => $duplicate->number.'-'.($offset + 2),
                ]);
            }
        }
    }
};
