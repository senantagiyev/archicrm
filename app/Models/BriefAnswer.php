<?php

namespace App\Models;

use App\Models\Concerns\HasBriefRoomKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BriefAnswer extends Model
{
    use HasBriefRoomKey;

    protected $fillable = [
        'brief_id', 'brief_question_id', 'brief_room_id',
        'value', 'delegated_to_designer', 'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'delegated_to_designer' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(BriefQuestion::class, 'brief_question_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(BriefRoom::class, 'brief_room_id');
    }

    public function isAnswered(): bool
    {
        if ($this->delegated_to_designer) {
            return true;
        }

        // Composite types (matrix, room_inventory, color_swatch, budget_range)
        // store an array that can be present but entirely empty.
        if (is_array($this->value)) {
            return collect($this->value)->flatten()->filter(fn ($v) => filled($v))->isNotEmpty();
        }

        return filled($this->value);
    }
}
