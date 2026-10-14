<?php

namespace App\Enums;

/**
 * Müəllif nəzarəti qeydinin icra vəziyyəti.
 *
 * Qeyd dizaynerin obyektdə gördüyü irad/göstərişdir; status onun podratçı
 * tərəfindən qəbul edilib-edilmədiyini izləyir. Dərc (`published_at`) isə
 * AYRI məsələdir — qeydin müştəriyə görünüb-görünmədiyini bildirir, ona görə
 * iki sahə bir-birini əvəz etmir.
 */
enum DiaryEntryStatus: string
{
    use Concerns\TranslatesLabels;

    case New = 'new';
    case UnderReview = 'under_review';
    case Reviewed = 'reviewed';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Yeni',
            self::UnderReview => 'Baxılır',
            self::Reviewed => 'Baxıldı',
            self::Done => 'İcra edildi',
            self::Cancelled => 'Ləğv edildi',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::UnderReview => 'warning',
            self::Reviewed => 'info',
            self::Done => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** @return array<string, string> Forma/filtr seçimləri üçün value => label. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
