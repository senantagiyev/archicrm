<?php

namespace App\Enums;

enum ClientStatus: string
{
    case Lead = 'lead';
    case Negotiation = 'negotiation';
    case Client = 'client';
    case Archive = 'archive';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Lid',
            self::Negotiation => 'Danışıqlar',
            self::Client => 'Müştəri',
            self::Archive => 'Arxiv',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Lead => 'warning',
            self::Negotiation => 'info',
            self::Client => 'success',
            self::Archive => 'gray',
        };
    }
}
