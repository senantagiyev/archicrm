<?php

namespace App\Enums;

enum ProjectType: string
{
    use Concerns\TranslatesLabels;

    case Apartment = 'apartment';
    case House = 'house';
    case Office = 'office';
    case Commercial = 'commercial';

    public function label(): string
    {
        return match ($this) {
            self::Apartment => 'Mənzil',
            self::House => 'Ev',
            self::Office => 'Ofis',
            self::Commercial => 'Kommersiya',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Apartment => 'heroicon-o-building-office',
            self::House => 'heroicon-o-home',
            self::Office => 'heroicon-o-briefcase',
            self::Commercial => 'heroicon-o-building-storefront',
        };
    }
}
