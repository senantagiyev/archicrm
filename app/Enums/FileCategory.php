<?php

namespace App\Enums;

enum FileCategory: string
{
    case Image = 'image';
    case Plan = 'plan';
    case Visualization = 'visualization';
    case Link = 'link';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Şəkillər',
            self::Plan => 'Planlar',
            self::Visualization => '3D / Vizualizasiya',
            self::Link => 'Linklər',
            self::Other => 'Köməkçi',
        };
    }
}
