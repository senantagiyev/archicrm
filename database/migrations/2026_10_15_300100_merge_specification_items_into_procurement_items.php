<?php

use App\Enums\SelectionStatus;
use App\Enums\SpecificationCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spesifikasiya sətirlərini vahid «Komplektasiya» pozisiyalarına birləşdirir.
 *
 * QAYDALAR (TZ §7, §10):
 *  - `specification_items` SİLİNMİR və onun sətirləri dəyişdirilmir — yalnız
 *    əlaqəsiz sətrə yeni pozisiyanın `procurement_item_id`-si yazılır.
 *  - Əlaqəsiz sətir → yeni pozisiya (sahə-sahə xəritə) + əlaqə.
 *  - Əlaqəli sətir → pozisiyada BOŞ olan sahələr doldurulur, dolu sahəyə
 *    toxunulmur. Qiymət/miqdar/cəm heç vaxt dəyişmir: onlar «Satınalmaya
 *    göndər» zamanı artıq köçürülüb və sonradan redaktə edilmiş ola bilər.
 *  - Yeni pozisiya `approval_status = draft` ilə yaranır: spesifikasiyanın
 *    «Təsdiqlənib» statusu studiyanın daxili seçimi idi, müştərinin
 *    razılaşdırması deyil. Onu razılaşdırma kimi köçürmək layihənin borcunu
 *    (`projects.debt`) səssizcə artırardı — seçim `selection_status`-a düşür.
 *  - «Satınalmaya hazır» olub əlaqəsi BOŞ olan sətir = pozisiyası sonradan
 *    əl ilə silinib (FK `nullOnDelete`). Onu yenidən yaratmaq silinmiş
 *    pozisiyanı dirildərdi — toxunulmur, sətir cədvəldə qalır.
 *  - İdempotentdir: ikinci işə salınmada bütün sətirlər artıq əlaqəlidir və
 *    yalnız «boş sahəni doldur» addımı işləyir (dəyişiklik yoxdur).
 *
 * Model yox, sorğu qurucusu işlədilir: model hook-ları (tenant konteksti,
 * activity log, optimistic lock) köçürmə üçün nəzərdə tutulmayıb.
 */
return new class extends Migration
{
    /** Pozisiyası silinmiş sayılan statuslar (yalnız «Satınalmaya göndər» bu statusu qoyurdu). */
    private const SENT_STATUSES = ['procurement_ready', 'purchased', 'delivered', 'installed'];

    public function up(): void
    {
        DB::table('specification_items')->orderBy('id')->chunkById(200, function ($specs): void {
            foreach ($specs as $spec) {
                DB::transaction(fn () => $spec->procurement_item_id
                    ? $this->fillMissing($spec)
                    : $this->createFor($spec));
            }
        });
    }

    public function down(): void
    {
        // Geri qaytarılmır: yaradılan pozisiyalar o vaxtdan redaktə, sifariş və
        // ödənişlə bağlanmış ola bilər. Spesifikasiya sətirləri yerində qalır.
    }

    private function createFor(object $spec): void
    {
        if (in_array($spec->status, self::SENT_STATUSES, true)) {
            return;
        }

        $price = round((float) $spec->client_price, 2);
        $qty = round((float) $spec->quantity, 2);

        $itemId = DB::table('procurement_items')->insertGetId([
            'tenant_id' => $spec->tenant_id ?? null,
            'project_id' => $spec->project_id,
            'name' => $spec->product_name,
            'brand' => $spec->brand,
            'model' => $spec->model,
            'category' => $this->categoryLabel($spec->category),
            'room' => $spec->room,
            'unit' => $spec->unit,
            'price' => $price,
            'supplier_cost' => $spec->supplier_cost,
            'qty' => $qty,
            // `ProcurementItem::saving` ilə eyni düstur; ehtiyat və çatdırılma 0-dır.
            'total' => round($qty * $price, 2),
            'store' => $spec->supplier,
            'url' => $spec->link,
            'photo_path' => $spec->image,
            'approval_status' => 'draft',
            'selection_status' => SelectionStatus::fromSpecificationStatus($spec->status)->value,
            'purchase_status' => $this->purchaseStatus($spec->status),
            'paid' => false,
            'visible_to_client' => false,
            'row_version' => 1,
            'created_at' => $spec->created_at ?? now(),
            'updated_at' => now(),
        ]);

        DB::table('specification_items')->where('id', $spec->id)
            ->whereNull('procurement_item_id')
            ->update(['procurement_item_id' => $itemId]);
    }

    private function fillMissing(object $spec): void
    {
        $item = DB::table('procurement_items')->where('id', $spec->procurement_item_id)->first();

        if ($item === null) {
            return;
        }

        $candidates = [
            'brand' => $spec->brand,
            'model' => $spec->model,
            'category' => $this->categoryLabel($spec->category),
            'room' => $spec->room,
            'unit' => $spec->unit,
            'store' => $spec->supplier,
            'url' => $spec->link,
            'photo_path' => $spec->image,
            'supplier_cost' => $spec->supplier_cost,
            'selection_status' => SelectionStatus::fromSpecificationStatus($spec->status)->value,
        ];

        $updates = [];

        foreach ($candidates as $column => $value) {
            if (blank($item->{$column}) && filled($value)) {
                $updates[$column] = $value;
            }
        }

        if ($updates !== []) {
            DB::table('procurement_items')->where('id', $item->id)->update($updates + ['updated_at' => now()]);
        }
    }

    private function categoryLabel(?string $category): ?string
    {
        if ($category === null) {
            return null;
        }

        return SpecificationCategory::tryFrom($category)?->label() ?? $category;
    }

    private function purchaseStatus(?string $status): string
    {
        return match ($status) {
            'purchased' => 'ordered',
            'delivered', 'installed' => 'delivered',
            default => 'planned',
        };
    }
};
