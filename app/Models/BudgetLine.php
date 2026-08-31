<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BudgetLine extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'project_id', 'stage_id', 'work_type', 'room', 'unit',
        'qty', 'work_price', 'material_price', 'total',
        'approval_status', 'position',
    ];

    protected function casts(): array
    {
        return [
            'approval_status' => ApprovalStatus::class,
            'qty' => 'decimal:2',
            'work_price' => 'decimal:2',
            'material_price' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            $line->total = round($line->qty * ((float) $line->work_price + (float) $line->material_price), 2);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['work_type', 'qty', 'work_price', 'material_price', 'approval_status'])
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

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
}
