<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Optimistic-lock counter for the domains §11.3 marks mandatory:
    // finance, approvals, versioning.
    private array $tables = [
        'invoices', 'expenses', 'payments', 'budget_lines', 'procurement_items',
        'approvals', 'deliverables', 'deliverable_versions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedInteger('row_version')->default(1)->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('row_version');
            });
        }
    }
};
