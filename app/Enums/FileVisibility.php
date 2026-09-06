<?php

namespace App\Enums;

/**
 * TZ v2.0 §7.16 — 5-level file visibility. Default is internal; a file only
 * reaches the client through an explicit "Publish to client" action.
 */
enum FileVisibility: string
{
    case Internal = 'internal';
    case ClientShared = 'client_shared';
    case ClientUploaded = 'client_uploaded';
    case Final = 'final';
    case Archive = 'archive';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Daxili',
            self::ClientShared => 'Müştəri ilə paylaşılıb',
            self::ClientUploaded => 'Müştəri yükləyib',
            self::Final => 'Final',
            self::Archive => 'Arxiv',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Internal => 'gray',
            self::ClientShared => 'info',
            self::ClientUploaded => 'warning',
            self::Final => 'success',
            self::Archive => 'gray',
        };
    }

    /** Which visibilities the customer portal may show. */
    public static function clientVisible(): array
    {
        return [self::ClientShared->value, self::ClientUploaded->value, self::Final->value];
    }
}
