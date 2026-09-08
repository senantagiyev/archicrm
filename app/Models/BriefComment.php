<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Designer's per-question clarification request (spec 13.2 №5–6, Screen 13).
 * open → client answers → resolved (a new BriefVersion is cut at that moment).
 */
class BriefComment extends Model
{
    protected $fillable = ['brief_id', 'brief_question_id', 'brief_room_id', 'user_id', 'body', 'status', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
