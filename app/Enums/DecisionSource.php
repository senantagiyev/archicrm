<?php

namespace App\Enums;

enum DecisionSource: string
{
    case Chat = 'chat';
    case Comment = 'comment';
    case Meeting = 'meeting';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Çat',
            self::Comment => 'Şərh',
            self::Meeting => 'Görüş',
            self::Manual => 'Əl ilə',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Chat => 'info',
            self::Comment => 'gray',
            self::Meeting => 'warning',
            self::Manual => 'primary',
        };
    }
}
