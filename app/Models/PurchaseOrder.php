<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseOrder extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'supplier_id', 'project_id', 'order_date', 'items',
        'subtotal', 'tax', 'total', 'payment_terms',
        'expected_delivery', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'items' => 'array',
            'order_date' => 'date',
            'expected_delivery' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $order): void {
            $items = collect($order->items ?? []);

            // Line items win when there are any: the three money fields were
            // plain inputs, so editing a line price left the header total at the
            // old number and nothing ever reconciled them. An order with no
            // itemisation (a lump sum) keeps the typed subtotal.
            if ($items->isNotEmpty()) {
                $order->subtotal = round(
                    $items->sum(fn ($item) => (float) ($item['qty'] ?? 0) * (float) ($item['price'] ?? 0)),
                    2,
                );
            }

            $order->total = round((float) $order->subtotal + (float) $order->tax, 2);
        });

        // A received order cannot go back to draft, and a cancelled one cannot
        // be resurrected — `status` is fillable with no state machine behind it.
        static::updating(function (self $order): void {
            if (! $order->isDirty('status')) {
                return;
            }

            $from = PurchaseOrderStatus::from($order->getRawOriginal('status'));
            $to = $order->status;

            if (! in_array($to, $from->allowedTransitions(), true)) {
                throw new \RuntimeException(
                    "Satınalma sifarişini «{$from->label()}» statusundan «{$to->label()}» statusuna keçirmək olmaz."
                );
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['supplier_id', 'project_id', 'status', 'total', 'expected_delivery'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
