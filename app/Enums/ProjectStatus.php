<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Done = 'done';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::Active => 'Aktiv',
            self::OnHold => 'Dayandırılıb',
            self::Done => 'Tamamlanıb',
            self::Archived => 'Arxiv',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Done => 'info',
            self::Archived => 'gray',
        };
    }
}
