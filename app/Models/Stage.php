<?php

namespace App\Models;

use App\Enums\StageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Stage extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'project_id', 'name', 'position',
        'date_plan_start', 'date_plan_end', 'date_fact_start', 'date_fact_end',
        'status', 'responsible_user_id', 'readiness', 'weight',
    ];

    protected function casts(): array
    {
        return [
            'status' => StageStatus::class,
            'date_plan_start' => 'date',
            'date_plan_end' => 'date',
            'date_fact_start' => 'date',
            'date_fact_end' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'date_plan_end', 'responsible_user_id'])
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

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** TZ §5.10: overdue when the plan end date passed and the stage is not done. */
    public function isOverdue(): bool
    {
        return $this->status !== StageStatus::Done
            && $this->date_plan_end !== null
            && $this->date_plan_end->isPast()
            && ! $this->date_plan_end->isToday();
    }
}
