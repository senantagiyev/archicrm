<?php

namespace App\Enums;

enum PunchIssueStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case ReadyForReview = 'ready_for_review';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açıq',
            self::Assigned => 'Təyin edilib',
            self::InProgress => 'İşlənir',
            self::ReadyForReview => 'Yoxlamaya hazır',
            self::Resolved => 'Həll edilib',
            self::Rejected => 'Rədd edilib',
            self::Closed => 'Bağlanıb',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'gray',
            self::Assigned => 'info',
            self::InProgress => 'warning',
            self::ReadyForReview => 'primary',
            self::Resolved => 'success',
            self::Rejected => 'danger',
            self::Closed => 'success',
        };
    }
}
