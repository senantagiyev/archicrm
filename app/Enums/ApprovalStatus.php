<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    use Concerns\TranslatesLabels;

    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::Pending => 'Razılaşmada',
            self::Approved => 'Razılaşılıb',
            self::Rejected => 'Rədd edilib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
