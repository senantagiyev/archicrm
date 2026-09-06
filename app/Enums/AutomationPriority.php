<?php

namespace App\Enums;

enum AutomationPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Kritik',
            self::High => 'Yüksək',
            self::Medium => 'Orta',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::High => 'warning',
            self::Medium => 'gray',
        };
    }
}
