<?php

namespace App\Enums;

/**
 * Brief lifecycle (spec Part 15 MVP / 13.2):
 * draft → sent → in_progress → submitted → needs_clarification → approved.
 */
enum BriefStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case NeedsClarification = 'needs_clarification';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::Sent => 'Göndərilib',
            self::InProgress => 'Doldurulur',
            self::Submitted => 'Təqdim edilib',
            self::NeedsClarification => 'Dəqiqləşdirmə lazımdır',
            self::Approved => 'Təsdiqlənib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'info',
            self::InProgress => 'warning',
            self::Submitted => 'success',
            self::NeedsClarification => 'danger',
            self::Approved => 'success',
        };
    }

    /** Client can no longer edit freely (only flagged questions during clarification). */
    public function isLocked(): bool
    {
        return in_array($this, [self::Submitted, self::NeedsClarification, self::Approved], true);
    }
}
