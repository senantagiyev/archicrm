<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lid → müştəri izi.
 *
 * Konversiyanın yeganə maneəsi düymənin `->visible(status !== Won)` şərti idi,
 * `status` isə adi fillable sahədir: operator statusu geri çevirəndə düymə
 * yenidən görünür və eyni adamdan ikinci müştəri yaranırdı. Konversiyanın
 * nəticəsi heç yerdə saxlanmadığı üçün nə təkrarı bloklamaq, nə də «bu lid
 * hansı müştəriyə çevrildi?» sualına cavab vermək mümkün deyildi.
 *
 * `nullOnDelete` (kaskad YOX): müştəri silinəndə lid tarixçəsi qalmalıdır —
 * lid marketinq hesabatının mənbəyidir, müştərinin əlavəsi deyil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('responsible_user_id')
                ->constrained('clients')
                ->nullOnDelete();

            // Konversiya hər dəfə bu sütuna baxır, hesabatlar isə «çevrilmiş
            // lidlər» seçimini bunun üzərindən qurur.
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropIndex(['client_id']);
            $table->dropColumn('client_id');
        });
    }
};
