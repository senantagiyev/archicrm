<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roomix-in «Procurement list» (`/complectation/<id>`) cədvəlindəki, bizdə
 * çatışmayan sütunlar. Yeni cədvəl qurulmur — hamısı mövcud sətirin sahəsidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_items', function (Blueprint $table) {
            // Roomix-də «Analog» — müştəriyə təklif olunan əvəzedici məhsul.
            $table->string('analog')->nullable()->after('name');
            // Ölçü vahidi (ədəd, m2, dəst) — mətn, çünki kataloq sabit deyil.
            $table->string('unit', 32)->nullable()->after('room');
            // Faizlə endirim: məbləği DB-də ikinci dəfə saxlamırıq, hesablanır.
            $table->decimal('discount_percent', 5, 2)->nullable()->after('total');
            // «Availability» — mövcudluq (stokda / sifarişlə / yoxdur).
            $table->string('availability', 32)->nullable()->after('discount_percent');
            // «Delivery» — gözlənilən çatdırılma tarixi.
            $table->date('delivery_date')->nullable()->after('purchase_status');
            $table->text('comment')->nullable()->after('cancel_comment');

            // Komplektasiya siyahısında studiyanın daxili sətirləri də olur
            // (alternativ variantlar, təchizatçı marjası, hələ razılaşdırılmamış
            // pozisiyalar). `BudgetLine`/`Document` ilə eyni məntiq: müştəri
            // YALNIZ açıq işarələnmiş sətirləri görür. Default `false` —
            // yeni sətir təsadüfən portala düşməsin (fail-safe).
            $table->boolean('visible_to_client')->default(false)->after('paid');
        });
    }

    public function down(): void
    {
        Schema::table('procurement_items', function (Blueprint $table) {
            $table->dropColumn([
                'analog', 'unit', 'discount_percent', 'availability',
                'delivery_date', 'comment', 'visible_to_client',
            ]);
        });
    }
};
