<?php

namespace App\Models\Concerns;

use App\Exceptions\RowVersionConflictException;

/**
 * Optimistic locking via a `row_version` column (TZ §11.3, mandatory for finance,
 * approvals and versioning). On every model update the current DB version is
 * compared to the version this instance was loaded with; a mismatch means another
 * user saved in between, so we abort with a RowVersionConflictException instead of
 * clobbering their change. On success the version is incremented.
 *
 * The compare-then-write window is a single request tick; it closes the realistic
 * "two people editing the same form" race that §11.3 targets. Query-builder mass
 * updates and soft-deletes bypass model events and are intentionally not locked.
 */
trait HasOptimisticLock
{
    public static function bootHasOptimisticLock(): void
    {
        static::creating(function ($model): void {
            if ($model->row_version === null) {
                $model->row_version = 1;
            }
        });

        static::updating(function ($model): void {
            $mine = (int) $model->getOriginal('row_version');

            $current = (int) static::query()
                ->withoutGlobalScopes()
                ->whereKey($model->getKey())
                ->value('row_version');

            if ($current !== $mine) {
                throw new RowVersionConflictException($current, $mine);
            }

            $model->row_version = $mine + 1;
        });
    }

    public function initializeHasOptimisticLock(): void
    {
        // Never let the version be mass-assigned from a form payload.
        $this->mergeGuarded(['row_version']);
    }
}
