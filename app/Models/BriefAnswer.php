<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BriefAnswer extends Model
{
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
        return $this->delegated_to_designer || filled($this->value);
    }
}
