<?php

namespace App\Models;

use App\Enums\DecisionSource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProjectDecision extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity;

    protected $fillable = [
        'project_id',
        'title',
        'category',
        'source',
        'related_entity_type',
        'related_entity_id',
        'decision',
        'made_by_user_id',
        'decided_at',
        'client_approved',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'source' => DecisionSource::class,
            'decided_at' => 'datetime',
            'client_approved' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'source', 'decision', 'client_approved', 'decided_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function madeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by_user_id');
    }
}
