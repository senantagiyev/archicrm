<?php

namespace App\Enums;

enum TimeEntrySource: string
{
    case Timer = 'timer';
    case Manual = 'manual';
    case AiSuggested = 'ai_suggested';

    public function label(): string
    {
        return match ($this) {
            self::Timer => 'Taymer',
            self::Manual => 'Əl ilə',
            self::AiSuggested => 'AI təklifi',
        };
    }
}
