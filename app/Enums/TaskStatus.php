<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'Gözləyir',
            self::InProgress => 'İşdə',
            self::Review => 'Yoxlamada',
            self::Done => 'Hazır',
            self::Cancelled => 'Ləğv edilib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Todo => 'gray',
            self::InProgress => 'info',
            self::Review => 'warning',
            self::Done => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Done, self::Cancelled], true);
    }
}
