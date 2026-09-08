<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Immutable snapshot of every answer at a lifecycle moment (spec 13.2 №1/№6):
 * v1 on submit, v(n+1) after each clarification round. Never updated.
 */
class BriefVersion extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['brief_id', 'version', 'snapshot', 'created_by_type', 'created_by_id', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime', 'version' => 'integer'];
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by');
    }

    /** Flat "key" / "room#label.key" => value map, for diffing. @return array<string, mixed> */
    public function flatten(): array
    {
        $out = [];
        foreach (($this->snapshot['general'] ?? []) as $key => $entry) {
            $out[$key] = $entry;
        }
        foreach (($this->snapshot['rooms'] ?? []) as $room) {
            foreach (($room['answers'] ?? []) as $key => $entry) {
                $out[($room['label'] ?? 'otaq').' · '.$key] = $entry;
            }
        }

        return $out;
    }

    /** Keys whose value changed since $previous (all keys when there is no previous). @return list<string> */
    public function changedKeysFrom(?self $previous): array
    {
        $now = $this->flatten();
        $before = $previous?->flatten() ?? [];

        return array_values(array_keys(array_filter(
            $now + $before,
            fn ($_, $k) => json_encode($now[$k] ?? null) !== json_encode($before[$k] ?? null),
            ARRAY_FILTER_USE_BOTH,
        )));
    }
}
