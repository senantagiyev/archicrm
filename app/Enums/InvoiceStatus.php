<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Sent = 'sent';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::Issued => 'Kəsilib',
            self::Sent => 'Göndərilib',
            self::PartiallyPaid => 'Qismən ödənilib',
            self::Paid => 'Ödənilib',
            self::Overdue => 'Vaxtı keçib',
            self::Cancelled => 'Ləğv edilib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'info',
            self::Sent => 'info',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Overdue => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
