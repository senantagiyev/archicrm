<?php

namespace App\Enums;

/**
 * Komplektasiya pozisiyasının SEÇİM mərhələsi (köhnə «Spesifikasiya»
 * statuslarının studiya daxili hissəsi).
 *
 * Bu, müştərinin rəsmi razılaşdırması (`approval_status`, yalnız
 * `ApprovalService`) DEYİL və satınalma/çatdırılma (`purchase_status`) də
 * deyil — üçü ayrı oxdur. Seçim statusu `projects.debt`-ə təsir etmir.
 */
enum SelectionStatus: string
{
    case Draft = 'draft';
    case Proposed = 'proposed';
    case ClientReview = 'client_review';
    case Selected = 'selected';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::Proposed => 'Təklif edilib',
            self::ClientReview => 'Müştəri baxışında',
            self::Selected => 'Seçilib',
            self::Rejected => 'Rədd edilib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Proposed, self::ClientReview => 'info',
            self::Selected => 'success',
            self::Rejected => 'danger',
        };
    }

    /**
     * Köhnə `SpecificationStatus` dəyərinin seçim oxundakı qarşılığı.
     * Təsdiqlənmiş və ondan sonrakı bütün statuslar «Seçilib» deməkdir.
     */
    public static function fromSpecificationStatus(?string $status): self
    {
        return match ($status) {
            'proposed' => self::Proposed,
            'client_review' => self::ClientReview,
            'rejected' => self::Rejected,
            'approved', 'procurement_ready', 'purchased', 'delivered', 'installed' => self::Selected,
            default => self::Draft,
        };
    }
}
