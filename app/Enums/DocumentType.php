<?php

namespace App\Enums;

enum DocumentType: string
{
    case Contract = 'contract';
    case Act = 'act';
    case BriefExport = 'brief_export';
    case Drawing = 'drawing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Müqavilə',
            self::Act => 'Qəbul aktı',
            self::BriefExport => 'Brif ixracı',
            self::Drawing => 'Çertyojlar',
            self::Other => 'Digər',
        };
    }
}
