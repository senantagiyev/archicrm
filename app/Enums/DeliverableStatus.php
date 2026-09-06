<?php

namespace App\Enums;

enum DeliverableStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case WaitingClient = 'waiting_client';
    case Approved = 'approved';
    case RevisionRequired = 'revision_required';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::InProgress => 'İşlənir',
            self::InReview => 'Daxili baxışda',
            self::WaitingClient => 'Müştəri gözlənilir',
            self::Approved => 'Təsdiqlənib',
            self::RevisionRequired => 'Düzəliş tələb olunur',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::InProgress => 'info',
            self::InReview => 'warning',
            self::WaitingClient => 'warning',
            self::Approved => 'success',
            self::RevisionRequired => 'danger',
        };
    }
}
