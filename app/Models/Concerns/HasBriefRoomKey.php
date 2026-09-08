<?php

namespace App\Models\Concerns;

/**
 * Keeps `room_key` (0 for general sections) in step with the nullable
 * `brief_room_id`. The unique index is built on `room_key` because NULLs do not
 * collide in a unique index — see the 2026_09_20 brief_room_key migration.
 */
trait HasBriefRoomKey
{
    public static function bootHasBriefRoomKey(): void
    {
        static::saving(function ($model) {
            $model->room_key = $model->brief_room_id ?? 0;
        });
    }
}
