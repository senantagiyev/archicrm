<?php

namespace App\Models;

use App\Enums\TimeEntrySource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'project_id', 'stage_id', 'task_id',
        'started_at', 'ended_at', 'duration_minutes',
        'hourly_cost_snapshot', 'comment', 'source',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'hourly_cost_snapshot' => 'decimal:2',
            'source' => TimeEntrySource::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            // Derive duration from start/end when both present.
            if ($entry->started_at && $entry->ended_at && $entry->ended_at->greaterThan($entry->started_at)) {
                $entry->duration_minutes = $entry->started_at->diffInMinutes($entry->ended_at);
            }
            // Snapshot the user's cost rate at entry time (TZ §7.27).
            if (blank($entry->hourly_cost_snapshot) || (float) $entry->hourly_cost_snapshot === 0.0) {
                $entry->hourly_cost_snapshot = optional($entry->user)->hourly_internal_cost ?? 0;
            }
        });
    }

    public function labourCost(): float
    {
        return round($this->duration_minutes / 60 * (float) $this->hourly_cost_snapshot, 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
