<?php

namespace App\Enums;

enum ContactLogType: string
{
    case Call = 'call';
    case Meeting = 'meeting';
    case Message = 'message';
    case Email = 'email';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Zəng',
            self::Meeting => 'Görüş',
            self::Message => 'Mesaj',
            self::Email => 'E-poçt',
            self::Note => 'Qeyd',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Call => 'heroicon-o-phone',
            self::Meeting => 'heroicon-o-user-group',
            self::Message => 'heroicon-o-chat-bubble-left',
            self::Email => 'heroicon-o-envelope',
            self::Note => 'heroicon-o-pencil-square',
        };
    }
}
