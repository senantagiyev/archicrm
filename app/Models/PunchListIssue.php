<?php

namespace App\Models;

use App\Enums\PunchIssuePriority;
use App\Enums\PunchIssueStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PunchListIssue extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity;

    protected $fillable = [
        'project_id', 'room', 'title', 'description', 'photo_url', 'priority',
        'responsible_user_id', 'due_date', 'status', 'resolved_photo_url',
    ];

    protected $attributes = [
        'status' => 'open',
        'priority' => 'normal',
    ];

    protected function casts(): array
    {
        return [
            'status' => PunchIssueStatus::class,
            'priority' => PunchIssuePriority::class,
            'due_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'room', 'priority', 'status', 'responsible_user_id', 'due_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
