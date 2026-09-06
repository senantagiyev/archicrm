<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::Ordered => 'Sifariş verilib',
            self::PartiallyReceived => 'Qismən qəbul edilib',
            self::Received => 'Qəbul edilib',
            self::Cancelled => 'Ləğv edilib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ordered => 'info',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }
}
