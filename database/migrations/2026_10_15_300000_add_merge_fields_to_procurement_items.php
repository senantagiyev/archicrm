<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komplektasiya = Spesifikasiyalar + Komplektasiya (TZ «Layihə naviqasiyası» §7).
 *
 *  - Spesifikasiya sətrinin komplektasiyada OLMAYAN sahələri pozisiyaya əlavə
 *    olunur (brend, model, xüsusiyyətlər, təchizatçı mayası, seçim statusu) —
 *    köçürmə zamanı heç nə itməsin.
 *  - `attachments`: pozisiyanın şəkilləri və texniki sənədləri pozisiyanın
 *    ÖZÜNDƏ saxlanılır, başqa bölməyə təkrar yüklənmir.
 *  - `budget_line_id`: smeta sətri ilə AÇIQ əlaqə — dəyər kopyalanmır.
 *  - `payments.procurement_item_id`: faktiki ödənişin mənbəyi mühasibatlıqdır;
 *    ödəniş qeydi pozisiyaya bu sütunla bağlanır.
 *
 * Sifariş dəyəri üçün sütun YOXDUR: o, satınalma sifarişinin pozisiya
 * sətirlərindən (`purchase_orders.items[].procurement_item_id`) hesablanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_items', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('analog');
            $table->string('model')->nullable()->after('brand');
            $table->text('characteristics')->nullable()->after('model');
            $table->decimal('supplier_cost', 12, 2)->nullable()->after('price');
            $table->string('selection_status', 32)->nullable()->after('approval_status');
            $table->json('attachments')->nullable()->after('attachment_url');
            $table->foreignId('budget_line_id')->nullable()->after('project_id')
                ->constrained('budget_lines')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('procurement_item_id')->nullable()->after('project_id')
                ->constrained('procurement_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('procurement_item_id');
        });

        Schema::table('procurement_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_line_id');
            $table->dropColumn([
                'brand', 'model', 'characteristics', 'supplier_cost',
                'selection_status', 'attachments',
            ]);
        });
    }
};
