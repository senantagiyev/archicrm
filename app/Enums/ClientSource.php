<?php

namespace App\Enums;

enum ClientSource: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case Website = 'website';
    case Referral = 'referral';
    case PhoneCall = 'phone_call';
    case Exhibition = 'exhibition';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::Website => 'Veb-sayt',
            self::Referral => 'Tövsiyə',
            self::PhoneCall => 'Telefon zəngi',
            self::Exhibition => 'Sərgi',
            self::Other => 'Digər',
        };
    }
}
