<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Supplier extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'category', 'contact', 'phone', 'email', 'website',
        'address', 'payment_terms', 'rating', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'category', 'contact', 'phone', 'email', 'rating'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function booted(): void
    {
        // A supplier with orders behind it cannot simply vanish: the FK is
        // cascadeOnDelete but SoftDeletes means the cascade never fires, so the
        // orders survived pointing at an invisible row — blank in the table,
        // unfilterable, and unattributable in any audit.
        static::deleting(function (self $supplier): void {
            if ($supplier->purchaseOrders()->exists()) {
                throw new \RuntimeException(
                    'Bu təchizatçının satınalma sifarişləri var — əvvəlcə sifarişləri başqa təchizatçıya köçürün.'
                );
            }
        });
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
