<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';

    /**
     * Statuses that represent money the studio actually bears. A rejected expense
     * is a claim the studio refused — counting it as cost understates every
     * margin on the profitability report.
     *
     * @return array<int, string>
     */
    public static function costBearing(): array
    {
        return [self::Pending->value, self::Approved->value, self::Paid->value];
    }

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
