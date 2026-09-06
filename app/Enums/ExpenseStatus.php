<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Gözləyir',
            self::Approved => 'Təsdiqlənib',
            self::Rejected => 'Rədd edilib',
            self::Paid => 'Ödənilib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Paid => 'success',
        };
    }
}
