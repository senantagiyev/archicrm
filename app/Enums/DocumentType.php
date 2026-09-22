<?php

namespace App\Enums;

enum DocumentType: string
{
    case Contract = 'contract';
    case Act = 'act';
    case BriefExport = 'brief_export';
    // Roomix-dəki «Technical specification»: brifdən doğan və müştəri ilə
    // razılaşdırılan sənəd. Brif ixracından fərqlidir — brif müştərinin
    // CAVABLARIDIR, texniki tapşırıq isə dizaynerin onlardan çıxardığı tapşırıq.
    case TechnicalSpec = 'technical_spec';
    case Drawing = 'drawing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Müqavilə',
            self::Act => 'Qəbul aktı',
            self::BriefExport => 'Brif ixracı',
            self::TechnicalSpec => 'Texniki tapşırıq',
            self::Drawing => 'Çertyojlar',
            self::Other => 'Digər',
        };
    }
}
