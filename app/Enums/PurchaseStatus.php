<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case Planned = 'planned';
    case Ordered = 'ordered';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planlaşdırılıb',
            self::Ordered => 'Sifariş edilib',
            self::Delivered => 'Çatdırılıb',
            self::Cancelled => 'Ləğv edilib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Ordered => 'info',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }
}
