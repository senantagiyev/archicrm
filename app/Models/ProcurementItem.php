<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProcurementItem extends Model
{
    use HasFactory, LogsActivity;

    // approval_status is NOT fillable — set only by ApprovalService (audit HIGH-2).
    protected $fillable = [
        'project_id', 'photo_path', 'sku', 'name', 'category', 'room',
        'price', 'qty', 'total', 'store', 'url',
        'purchase_status', 'cancel_comment', 'paid',
    ];

    protected function casts(): array
    {
        return [
            'approval_status' => ApprovalStatus::class,
            'purchase_status' => PurchaseStatus::class,
            'price' => 'decimal:2',
            'qty' => 'decimal:2',
            'total' => 'decimal:2',
            'paid' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->total = round($item->qty * (float) $item->price, 2);
        });

        // TZ §5.10: enforce the deletion lock at the model, not just the UI (audit MEDIUM-1).
        static::deleting(function (self $item): void {
            if ($item->isDeletionLocked()) {
                throw new \RuntimeException('Razılaşdırılmış və ödənilmiş pozisiya silinə bilməz.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'price', 'qty', 'approval_status', 'purchase_status', 'paid'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * TZ §5.10: an approved item with a recorded payment can never be deleted —
     * only cancelled with a mandatory comment.
     */
    public function isDeletionLocked(): bool
    {
        return $this->approval_status === ApprovalStatus::Approved && $this->paid;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
}
