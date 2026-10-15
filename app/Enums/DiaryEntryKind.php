<?php

namespace App\Enums;

/**
 * Müəllif nəzarəti qeydinin növü (TZ §8): obyektə səfər və ya hesabat.
 * Qüsur / irad bu siyahıda deyil — o, «İradlar»dadır (`PunchListIssue`).
 */
enum DiaryEntryKind: string
{
    use Concerns\TranslatesLabels;

    case Visit = 'visit';
    case Report = 'report';

    public function label(): string
    {
        return match ($this) {
            self::Visit => 'Səfər',
            self::Report => 'Hesabat',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Visit => 'info',
            self::Report => 'gray',
        };
    }

    /** @return array<string, string> Forma/filtr seçimləri üçün value => label. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $kind) => [$kind->value => $kind->label()])->all();
    }
}
