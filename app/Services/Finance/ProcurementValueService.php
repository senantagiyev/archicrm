<?php

namespace App\Services\Finance;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Payment;
use App\Models\ProcurementItem;
use App\Models\PurchaseOrder;
use Illuminate\Support\Collection;

/**
 * Komplektasiya pozisiyasının ÜÇ AYRI dəyər göstəricisi (TZ «Layihə
 * naviqasiyası» §7, qəbul meyarı 18). Heç biri digərindən hesablanmır və heç
 * biri digərini yenidən yazmır — hər birinin öz mənbəyi var:
 *
 *  1. Plan dəyəri (seçim üçün) — `procurement_items.total`
 *     (qiymət × miqdar + ehtiyat + çatdırılma). Mənbə: Komplektasiya.
 *  2. Sifarişin təsdiqlənmiş dəyəri — satınalma sifarişlərinin bu pozisiyaya
 *     bağlanmış sətirləri (`purchase_orders.items[].procurement_item_id`),
 *     yalnız `PurchaseOrderStatus::costBearing()` statuslu sifarişlərdə.
 *     Qaralama sifariş hələ təsdiqlənmiş öhdəlik deyil. Mənbə: Satınalma.
 *  3. Faktiki ödəniş — pozisiyaya bağlanmış «Ödənilib» statuslu `Payment`
 *     qeydləri. Mənbə: Mühasibatlıq.
 *
 * Bağlantı başqa layihənin pozisiyasına işarə edirsə (sifariş/ödəniş başqa
 * layihədədir), sayılmır — yad layihənin göstəricisi dəyişməsin.
 *
 * Nüsxə öz daxilində layihə üzrə nəticəni yadda saxlayır (cədvəldə N+1 olmasın);
 * ömrünü çağıran idarə edir — konteynerdə singleton DEYİL, köhnə rəqəm qalmasın.
 */
class ProcurementValueService
{
    /** @var array<int, array<int, list<array{order_id: int, status: PurchaseOrderStatus, qty: float, price: float, value: float}>>> */
    private array $orderLines = [];

    /** @var array<int, Collection<int, Collection<int, Payment>>> */
    private array $payments = [];

    /**
     * @return array{
     *     planned: float,
     *     ordered: float|null,
     *     orders: list<array{order_id: int, status: PurchaseOrderStatus, qty: float, price: float, value: float}>,
     *     paid: float,
     *     payments: Collection<int, Payment>
     * }
     */
    public function summary(ProcurementItem $item): array
    {
        return [
            'planned' => $this->plannedTotal($item),
            'ordered' => $this->orderedTotal($item),
            'orders' => $this->orderLinesFor($item),
            'paid' => $this->paidTotal($item),
            'payments' => $this->paymentsFor($item),
        ];
    }

    public function plannedTotal(ProcurementItem $item): float
    {
        return round((float) $item->total, 2);
    }

    /** `null` — təsdiqlənmiş sifarişdə bu pozisiya yoxdur (0 ilə qarışdırılmasın). */
    public function orderedTotal(ProcurementItem $item): ?float
    {
        $lines = $this->orderLinesFor($item);

        if ($lines === []) {
            return null;
        }

        return round(array_sum(array_column($lines, 'value')), 2);
    }

    /**
     * @return list<array{order_id: int, status: PurchaseOrderStatus, qty: float, price: float, value: float}>
     */
    public function orderLinesFor(ProcurementItem $item): array
    {
        if ($item->project_id === null) {
            return [];
        }

        return $this->orderLinesByItem((int) $item->project_id)[$item->getKey()] ?? [];
    }

    public function paidTotal(ProcurementItem $item): float
    {
        return round((float) $this->paymentsFor($item)
            ->filter(fn (Payment $payment) => $payment->status === PaymentStatus::Paid)
            ->sum('amount'), 2);
    }

    /** @return Collection<int, Payment> bütün bağlı ödəniş qeydləri (gözlənilən də daxil) */
    public function paymentsFor(ProcurementItem $item): Collection
    {
        if ($item->project_id === null) {
            return collect();
        }

        return $this->paymentsByItem((int) $item->project_id)->get($item->getKey(), collect());
    }

    /**
     * @return array<int, list<array{order_id: int, status: PurchaseOrderStatus, qty: float, price: float, value: float}>>
     */
    private function orderLinesByItem(int $projectId): array
    {
        if (array_key_exists($projectId, $this->orderLines)) {
            return $this->orderLines[$projectId];
        }

        $byItem = [];

        $orders = PurchaseOrder::query()
            ->where('project_id', $projectId)
            ->whereIn('status', PurchaseOrderStatus::costBearing())
            ->orderBy('id')
            ->get(['id', 'project_id', 'status', 'items']);

        foreach ($orders as $order) {
            foreach ($order->items ?? [] as $line) {
                $itemId = (int) ($line['procurement_item_id'] ?? 0);

                if ($itemId === 0) {
                    continue;
                }

                $qty = (float) ($line['qty'] ?? 0);
                $price = (float) ($line['price'] ?? 0);

                $byItem[$itemId][] = [
                    'order_id' => (int) $order->id,
                    'status' => $order->status,
                    'qty' => $qty,
                    'price' => $price,
                    'value' => round($qty * $price, 2),
                ];
            }
        }

        return $this->orderLines[$projectId] = $byItem;
    }

    /** @return Collection<int, Collection<int, Payment>> */
    private function paymentsByItem(int $projectId): Collection
    {
        return $this->payments[$projectId] ??= Payment::query()
            ->where('project_id', $projectId)
            ->whereNotNull('procurement_item_id')
            ->orderBy('id')
            ->get()
            ->groupBy('procurement_item_id');
    }
}
