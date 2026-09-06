<?php

namespace App\Services\Design;

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
        if ($item->status !== SpecificationStatus::Approved) {
            throw new RuntimeException('Yalnız təsdiqlənmiş spesifikasiya satınalmaya göndərilə bilər.');
        }

        if ($item->procurement_item_id) {
            throw new RuntimeException('Bu spesifikasiya artıq satınalmaya göndərilib.');
        }

        return DB::transaction(function () use ($item) {
            $procurement = ProcurementItem::create([
                'project_id' => $item->project_id,
                'name' => $item->product_name,
                'category' => $item->category->label(),
                'room' => $item->room,
                'price' => $item->client_price,
                'qty' => $item->quantity,
                'store' => $item->supplier,
                'url' => $item->link,
                'photo_path' => $item->image,
            ]);

            $item->forceFill([
                'procurement_item_id' => $procurement->id,
                'status' => SpecificationStatus::ProcurementReady,
            ])->save();

            return $procurement;
        });
    }
}
