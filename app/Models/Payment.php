<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOptimisticLock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payment extends Model
{
    use BelongsToTenant, HasFactory, HasOptimisticLock, LogsActivity;

    protected $fillable = [
        'project_id', 'title', 'amount', 'due_date', 'paid_at', 'method', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $payment): void {
            // `Expense.php:41` ilə eyni qoruma: `minValue(0.01)` yalnız
            // `PaymentsRelationManager.php:38` formasındadır, yəni idxal, konsol
            // və API yolu açıq idi. Mənfi ödəniş `ProfitabilityService`-də
            // gəliri (`SUM(amount)`) azaldır — 1 000 + (−400) = 600 — və layihənin
            // borc rəqəmini pozur. Sıfır da qəbul edilmir: məbləğsiz ödəniş
            // sətri heç bir maliyyə hadisəsini ifadə etmir.
            if ((float) $payment->amount <= 0) {
                throw new \RuntimeException('Ödənişin məbləği sıfırdan böyük olmalıdır.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'amount', 'status', 'paid_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
