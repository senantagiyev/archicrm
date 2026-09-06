<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Meeting extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'project_id', 'title', 'starts_at', 'ends_at', 'participants',
        'location', 'online_link', 'notes', 'recording_link',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'participants' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['project_id', 'title', 'starts_at', 'ends_at', 'location', 'online_link'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
