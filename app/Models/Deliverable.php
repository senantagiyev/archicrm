<?php

namespace App\Models;

use App\Enums\DeliverableStatus;
use App\Enums\DeliverableType;
use App\Models\Concerns\HasOptimisticLock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Deliverable extends Model
{
    use HasFactory, HasOptimisticLock, LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id', 'stage_id', 'type', 'title', 'description',
        'current_version_id', 'status', 'responsible_user_id', 'client_visible',
    ];

    protected function casts(): array
    {
        return [
            'type' => DeliverableType::class,
            'status' => DeliverableStatus::class,
            'client_visible' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'status', 'current_version_id', 'client_visible'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DeliverableVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DeliverableVersion::class, 'current_version_id');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
}
