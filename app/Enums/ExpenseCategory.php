<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Supplier = 'supplier';
    case Contractor = 'contractor';
    case Transport = 'transport';
    case Printing = 'printing';
    case Site = 'site';
    case Travel = 'travel';
    case Software = 'software';
    case Material = 'material';
    case Labor = 'labor';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Təchizatçı',
            self::Contractor => 'Podratçı',
            self::Transport => 'Nəqliyyat',
            self::Printing => 'Çap',
            self::Site => 'Obyekt',
            self::Travel => 'Ezamiyyət',
            self::Software => 'Proqram təminatı',
            self::Material => 'Material',
            self::Labor => 'İşçi qüvvəsi',
            self::Other => 'Digər',
        };
    }
}
