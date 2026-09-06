<?php

namespace App\Enums;

/**
 * TZ v2.0 §7.12 / §9.2 — DeliverableVersion state machine. Transitions not in
 * ALLOWED are rejected (409-style). `approved`/`locked` are immutable: the file
 * cannot be edited/replaced/deleted, only a new version created.
 */
enum DeliverableVersionStatus: string
{
    case Draft = 'draft';
    case InternalReview = 'internal_review';
    case ReadyForClient = 'ready_for_client';
    case SentForApproval = 'sent_for_approval';
    case RevisionRequired = 'revision_required';
    case Approved = 'approved';
    case Locked = 'locked';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::InternalReview => 'Daxili baxış',
            self::ReadyForClient => 'Müştəriyə hazır',
            self::SentForApproval => 'Razılaşdırmaya göndərilib',
            self::RevisionRequired => 'Düzəliş tələb olunur',
            self::Approved => 'Təsdiqlənib',
            self::Locked => 'Kilidlənib',
            self::Superseded => 'Əvəzlənib',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft, self::Superseded => 'gray',
            self::InternalReview, self::ReadyForClient => 'info',
            self::SentForApproval => 'warning',
            self::RevisionRequired => 'danger',
            self::Approved, self::Locked => 'success',
        };
    }

    public function isImmutable(): bool
    {
        return in_array($this, [self::Approved, self::Locked], true);
    }

    /** @return array<string, list<string>> from => [allowed to] */
    public static function allowed(): array
    {
        return [
            self::Draft->value => [self::InternalReview->value, self::Superseded->value],
            self::InternalReview->value => [self::ReadyForClient->value, self::Draft->value, self::Superseded->value],
            self::ReadyForClient->value => [self::SentForApproval->value, self::InternalReview->value, self::Superseded->value],
            self::SentForApproval->value => [self::RevisionRequired->value, self::Approved->value, self::Superseded->value],
            self::RevisionRequired->value => [self::InternalReview->value, self::SentForApproval->value, self::Superseded->value],
            self::Approved->value => [self::Locked->value],
            self::Locked->value => [],
            self::Superseded->value => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::allowed()[$this->value] ?? [], true);
    }
}
