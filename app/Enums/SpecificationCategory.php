<?php

namespace App\Enums;

enum SpecificationCategory: string
{
    case Furniture = 'furniture';
    case Lighting = 'lighting';
    case Sanitaryware = 'sanitaryware';
    case Finishing = 'finishing';
    case Doors = 'doors';
    case Appliances = 'appliances';
    case Electrical = 'electrical';
    case Textile = 'textile';
    case Decor = 'decor';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Furniture => 'Mebel',
            self::Lighting => 'İşıqlandırma',
            self::Sanitaryware => 'Santexnika',
            self::Finishing => 'Bəzək materialları',
            self::Doors => 'Qapılar',
            self::Appliances => 'Məişət texnikası',
            self::Electrical => 'Elektrik',
            self::Textile => 'Tekstil',
            self::Decor => 'Dekor',
            self::Custom => 'Digər',
        };
    }
}
