<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Qaralama',
            self::Ordered => 'Sifariş verilib',
            self::PartiallyReceived => 'Qismən qəbul edilib',
            self::Received => 'Qəbul edilib',
            self::Cancelled => 'Ləğv edilib',
        };
    }

    /**
     * Statuses at which the studio is committed to paying the supplier, so the
     * order counts as cost. A draft is not yet an order; a cancelled one never
     * became a payable.
     *
     * @return array<int, string>
     */
    public static function costBearing(): array
    {
        return [self::Ordered->value, self::PartiallyReceived->value, self::Received->value];
    }

    /**
     * The order a purchase actually moves through. Without this every status was
     * reachable from every other, so a studio could mark goods received that were
     * never ordered, or un-cancel a cancelled order.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Ordered, self::Cancelled],
            self::Ordered => [self::PartiallyReceived, self::Received, self::Cancelled],
            self::PartiallyReceived => [self::Received, self::Cancelled],
            // Terminal.
            self::Received, self::Cancelled => [],
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ordered => 'info',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }
}
