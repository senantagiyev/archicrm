<?php

namespace App\Enums;

use App\Models\Deliverable;

/**
 * Material kartında göstərilən razılaşdırma statusu (TZ «Layihə naviqasiyası»
 * §6): «Göndərilməyib / Razılaşdırmadadır / Təsdiqlənib / Düzəliş tələb olunur».
 *
 * Saxlanılmır — materialın CARİ versiyasının son razılaşdırmasından hesablanır
 * ({@see Deliverable::approvalState()}). Yeni versiya yarandıqda
 * status özü «Göndərilməyib»ə qayıdır: köhnə versiyanın təsdiqi miras qalmır.
 */
enum MaterialApprovalState: string
{
    case NotSent = 'not_sent';
    case InApproval = 'in_approval';
    case Approved = 'approved';
    case RevisionRequired = 'revision_required';

    public function label(): string
    {
        return match ($this) {
            self::NotSent => 'Göndərilməyib',
            self::InApproval => 'Razılaşdırmadadır',
            self::Approved => 'Təsdiqlənib',
            self::RevisionRequired => 'Düzəliş tələb olunur',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotSent => 'gray',
            self::InApproval => 'warning',
            self::Approved => 'success',
            self::RevisionRequired => 'danger',
        };
    }

    /** Razılaşdırma sətrinin statusu → kart statusu (`draft` = geri çəkilib). */
    public static function fromApprovalStatus(?ApprovalStatus $status): self
    {
        return match ($status) {
            ApprovalStatus::Pending => self::InApproval,
            ApprovalStatus::Approved => self::Approved,
            ApprovalStatus::Rejected => self::RevisionRequired,
            default => self::NotSent,
        };
    }
}
