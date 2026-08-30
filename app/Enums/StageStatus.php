<?php

namespace App\Enums;

enum StageStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Başlanmayıb',
            self::InProgress => 'İşdə',
            self::Review => 'Yoxlamada',
            self::Done => 'Hazır',
            self::Overdue => 'Gecikib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotStarted => 'gray',
            self::InProgress => 'info',
            self::Review => 'warning',
            self::Done => 'success',
            self::Overdue => 'danger',
        };
    }
}
