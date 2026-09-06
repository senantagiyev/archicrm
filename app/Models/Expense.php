<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Expense extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id', 'category', 'vendor', 'amount', 'currency',
        'date', 'description', 'status', 'created_by_user_id', 'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'status' => ExpenseStatus::class,
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['category', 'vendor', 'amount', 'status', 'project_id', 'approved_by_user_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
