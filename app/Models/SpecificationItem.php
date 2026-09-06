<?php

namespace App\Models;

use App\Enums\SpecificationCategory;
use App\Enums\SpecificationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SpecificationItem extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity;

    protected $fillable = [
        'project_id', 'category', 'room', 'product_name', 'brand', 'model',
        'supplier', 'client_price', 'supplier_cost', 'quantity', 'unit',
        'image', 'link', 'status', 'procurement_item_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => SpecificationCategory::class,
            'status' => SpecificationStatus::class,
            'client_price' => 'decimal:2',
            'supplier_cost' => 'decimal:2',
            'quantity' => 'decimal:2',
        ];
    }

    /** supplier_cost is F-level sensitive — never expose in client payloads. */
    protected $hidden = ['supplier_cost'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['product_name', 'status', 'client_price', 'procurement_item_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function procurementItem(): BelongsTo
    {
        return $this->belongsTo(ProcurementItem::class);
    }
}
