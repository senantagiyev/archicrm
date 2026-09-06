<?php

namespace App\Enums;

enum SpecificationStatus: string
{
    case Draft = 'draft';
    case Proposed = 'proposed';
    case ClientReview = 'client_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ProcurementReady = 'procurement_ready';
    case Purchased = 'purchased';
    case Delivered = 'delivered';
    case Installed = 'installed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::Proposed => 'Təklif edilib',
            self::ClientReview => 'Müştəri baxışında',
            self::Approved => 'Təsdiqlənib',
            self::Rejected => 'Rədd edilib',
            self::ProcurementReady => 'Satınalmaya hazır',
            self::Purchased => 'Alınıb',
            self::Delivered => 'Çatdırılıb',
            self::Installed => 'Quraşdırılıb',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Proposed, self::ClientReview => 'info',
            self::Approved, self::ProcurementReady => 'warning',
            self::Rejected => 'danger',
            self::Purchased, self::Delivered, self::Installed => 'success',
        };
    }
}
