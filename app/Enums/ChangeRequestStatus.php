<?php

namespace App\Enums;

/**
 * TZ v2.0 §7.15 / §9.3 — ChangeRequest state machine.
 */
enum ChangeRequestStatus: string
{
    case Draft = 'draft';
    case ImpactAssessment = 'impact_assessment';
    case WaitingInternalApproval = 'waiting_internal_approval';
    case WaitingClientApproval = 'waiting_client_approval';
    case Approved = 'approved';
    case InImplementation = 'in_implementation';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::ImpactAssessment => 'Təsir qiymətləndirməsi',
            self::WaitingInternalApproval => 'Daxili təsdiq gözlənilir',
            self::WaitingClientApproval => 'Müştəri təsdiqi gözlənilir',
            self::Approved => 'Təsdiqlənib',
            self::InImplementation => 'İcrada',
            self::Completed => 'Tamamlanıb',
            self::Rejected => 'Rədd edilib',
            self::Cancelled => 'Ləğv edilib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::ImpactAssessment, self::WaitingInternalApproval, self::WaitingClientApproval => 'warning',
            self::Approved, self::InImplementation => 'info',
            self::Completed => 'success',
            self::Rejected, self::Cancelled => 'danger',
        };
    }

    /** @return array<string, list<string>> */
    public static function allowed(): array
    {
        return [
            self::Draft->value => [self::ImpactAssessment->value, self::Cancelled->value],
            self::ImpactAssessment->value => [self::WaitingInternalApproval->value, self::Cancelled->value],
            self::WaitingInternalApproval->value => [self::WaitingClientApproval->value, self::Rejected->value, self::Cancelled->value],
            self::WaitingClientApproval->value => [self::Approved->value, self::Rejected->value, self::Cancelled->value],
            self::Approved->value => [self::InImplementation->value],
            self::InImplementation->value => [self::Completed->value],
            self::Completed->value => [],
            self::Rejected->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::allowed()[$this->value] ?? [], true);
    }
}
