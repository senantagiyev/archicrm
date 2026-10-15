<?php

namespace App\Services\Design;

use App\Enums\SelectionStatus;
use App\Enums\SpecificationStatus;
use App\Models\ProcurementItem;
use App\Models\SpecificationItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TZ v2.0 §7.18 / §8.14 — an approved specification item becomes a procurement
 * item via one action ("Send to Procurement").
 */
class SpecificationService
{
    public function sendToProcurement(SpecificationItem $item): ProcurementItem
    {
        return DB::transaction(function () use ($item) {
            // Re-read inside the transaction with a row lock. Checking before
            // opening it meant a double-click created TWO procurement items —
            // the spec pointed at one, and the orphan still counted toward the
            // client's debt as soon as anyone approved it.
            $item = $item->newQuery()->lockForUpdate()->findOrFail($item->getKey());

            if ($item->status !== SpecificationStatus::Approved) {
                throw new RuntimeException('Yalnız təsdiqlənmiş spesifikasiya satınalmaya göndərilə bilər.');
            }

            if ($item->procurement_item_id) {
                throw new RuntimeException('Bu spesifikasiya artıq satınalmaya göndərilib.');
            }

            $procurement = ProcurementItem::create([
                'project_id' => $item->project_id,
                'name' => $item->product_name,
                'brand' => $item->brand,
                'model' => $item->model,
                'category' => $item->category->label(),
                'room' => $item->room,
                'unit' => $item->unit,
                'price' => $item->client_price,
                'supplier_cost' => $item->supplier_cost,
                'qty' => $item->quantity,
                'store' => $item->supplier,
                'url' => $item->link,
                'photo_path' => $item->image,
                'selection_status' => SelectionStatus::Selected,
            ]);

            $item->forceFill([
                'procurement_item_id' => $procurement->id,
                'status' => SpecificationStatus::ProcurementReady,
            ])->save();

            return $procurement;
        });
    }
}
