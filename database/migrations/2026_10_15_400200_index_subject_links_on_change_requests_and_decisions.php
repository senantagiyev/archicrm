<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dəyişiklik sorğuları və qərarlar obyektin daxilində göstərilir (TZ §9):
 * hər material / mərhələ / irad sətrində «bu obyektin sorğuları» süzgəci
 * işləyir. Mövcud dəyərlərə TOXUNULMUR — əlaqəsiz köhnə qeydlər yoxlama
 * siyahısına düşür, avtomatik bölüşdürülmür (TZ §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->index(['project_id', 'affected_entity_type', 'affected_entity_id'], 'change_requests_subject_index');
        });

        Schema::table('project_decisions', function (Blueprint $table) {
            $table->index(['project_id', 'related_entity_type', 'related_entity_id'], 'project_decisions_subject_index');
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropIndex('change_requests_subject_index');
        });

        Schema::table('project_decisions', function (Blueprint $table) {
            $table->dropIndex('project_decisions_subject_index');
        });
    }
};
