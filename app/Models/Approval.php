<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Approval extends Model
{
    use LogsActivity;

    protected $fillable = [
        'approvable_type', 'approvable_id', 'project_id',
        'requested_by_user_id', 'client_user_id',
        'status', 'comment', 'respond_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'respond_by' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'comment', 'decided_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    /** Human-readable label of what is being approved. */
    public function subjectLabel(): string
    {
        return match (true) {
            $this->approvable instanceof BudgetLine => 'Smeta: '.$this->approvable->work_type,
            $this->approvable instanceof ProcurementItem => 'Komplektasiya: '.$this->approvable->name,
            $this->approvable instanceof Stage => 'Mərhələ: '.$this->approvable->name,
            $this->approvable instanceof Document => 'Sənəd: '.$this->approvable->title,
            default => 'Obyekt #'.$this->approvable_id,
        };
    }
}
