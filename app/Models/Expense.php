<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOptimisticLock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Expense extends Model
{
    use BelongsToTenant, HasFactory, HasOptimisticLock, LogsActivity, SoftDeletes;

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

    protected static function booted(): void
    {
        static::saving(function (self $expense): void {
            // Enforced at the model, not only in the Filament form: any other
            // write path (import, console, API) could store a negative expense,
            // which flips the sign of the studio's cost.
            if ((float) $expense->amount <= 0) {
                throw new \RuntimeException('Xərcin məbləği sıfırdan böyük olmalıdır.');
            }

            // Four-eyes: the person who filed a claim cannot also approve it.
            $approved = in_array($expense->status, [ExpenseStatus::Approved, ExpenseStatus::Paid], true);

            if ($approved && $expense->approved_by_user_id
                && $expense->approved_by_user_id === $expense->created_by_user_id) {
                throw new \RuntimeException('Xərci yazan şəxs onu özü təsdiqləyə bilməz.');
            }
        });
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
