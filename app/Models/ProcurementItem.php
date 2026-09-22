<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\PurchaseStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOptimisticLock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProcurementItem extends Model
{
    use BelongsToTenant, HasFactory, HasOptimisticLock, LogsActivity;

    // approval_status is NOT fillable — set only by ApprovalService (audit HIGH-2).
    protected $fillable = [
        'project_id', 'photo_path', 'sku', 'name', 'analog', 'category', 'room', 'unit',
        'price', 'qty', 'total', 'discount_percent', 'availability',
        'store', 'url', 'attachment_url',
        'reserve_percent', 'delivery_assembly_price',
        'purchase_status', 'delivery_date', 'cancel_comment', 'comment', 'paid',
        'visible_to_client',
    ];

    protected function casts(): array
    {
        return [
            'approval_status' => ApprovalStatus::class,
            'purchase_status' => PurchaseStatus::class,
            'price' => 'decimal:2',
            'qty' => 'decimal:2',
            'total' => 'decimal:2',
            'reserve_percent' => 'decimal:2',
            'delivery_assembly_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'delivery_date' => 'date',
            'paid' => 'boolean',
            'visible_to_client' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            // Everything the client is billed for: goods, the reserve markup on
            // them, and delivery/assembly. Leaving the last two out understated
            // projects.debt by exactly the amount the studio pays out.
            $goods = round((float) $item->qty * (float) $item->price, 2);
            $reserve = round($goods * ((float) $item->reserve_percent / 100), 2);

            $item->total = round($goods + $reserve + (float) $item->delivery_assembly_price, 2);
        });

        // TZ §5.10: enforce the deletion lock at the model, not just the UI (audit MEDIUM-1).
        static::deleting(function (self $item): void {
            if ($item->isDeletionLocked()) {
                throw new \RuntimeException('Razılaşdırılmış və ödənilmiş pozisiya silinə bilməz.');
            }
        });

        // The lock reads `paid`, so clearing that checkbox used to unlock the row.
        // An approved item's payment flag is not an ordinary editable field.
        static::updating(function (self $item): void {
            // getRawOriginal, not getOriginal: the latter applies the cast and
            // hands back an ApprovalStatus instance, which never equals a string.
            if ($item->isDirty('paid')
                && $item->getRawOriginal('paid')
                && $item->getRawOriginal('approval_status') === ApprovalStatus::Approved->value) {
                throw new \RuntimeException('Razılaşdırılmış pozisiyanın "Ödənilib" işarəsi geri alına bilməz.');
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

    /**
     * Endirim tətbiq olunmuş yekun məbləğ (Roomix-də «With discount» sütunu).
     *
     * Endirim ayrıca sütunda SAXLANMIR, hər dəfə `total`-dan hesablanır —
     * belə olduqda `qty`/`price`/`reserve` dəyişəndə köhnə, «donmuş» endirimli
     * məbləğ qalmır. Faiz boşdursa sətir endirimsizdir.
     */
    public function totalWithDiscount(): float
    {
        $total = (float) $this->total;
        $percent = (float) ($this->discount_percent ?? 0);

        // Mənfi və ya 100-dən böyük faiz məbləği şişirdər/mənfiyə salardı —
        // hesablamada hər halda məhdudlaşdırılır.
        $percent = max(0.0, min(100.0, $percent));

        return round($total - ($total * $percent / 100), 2);
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
