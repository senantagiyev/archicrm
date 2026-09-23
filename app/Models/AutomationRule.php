<?php

namespace App\Models;

use App\Enums\AutomationPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AutomationRule extends Model
{
    /**
     * A studio's own override row shadows the platform-wide default
     * (tenant_id = null), so toggling a rule in one studio cannot silence
     * another studio's notifications.
     */
    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query
            ->where(fn (Builder $inner) => $inner->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id is null');
    }

    use LogsActivity;

    /**
     * `tenant_id` QƏSDƏN buradadır: copy-on-write override-i
     * `updateOrCreate(['tenant_id' => …, 'code' => …], …)` ilə yaradılır,
     * `updateOrCreate()` isə `firstOrNew()` + `fill()` işlədir və `fill()`
     * `$fillable`-dan kənar açarı SƏSSİZCƏ atır. Onsuz studiya override-i
     * əvəzinə ikinci `tenant_id = NULL` platforma sətri yaranırdı
     * ((tenant_id, code) unikal indeksi NULL-ları fərqli saydığı üçün insert
     * keçirdi) və `AutomationEngine::isEnabled()` `pluck('enabled','code')`
     * ilə oxuduğu üçün SONUNCU sətir qalib gəlirdi — yəni beta studiyasının
     * «söndür» qərarı alfa-nın bildirişlərini də söndürürdü.
     */
    protected $fillable = [
        'tenant_id', 'code', 'name', 'trigger', 'priority', 'enabled', 'conditions', 'actions',
    ];

    protected function casts(): array
    {
        return [
            'priority' => AutomationPriority::class,
            'enabled' => 'boolean',
            'conditions' => 'array',
            'actions' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Turning a rule on/off is a governance action — it must be auditable.
        return LogOptions::defaults()
            ->logOnly(['enabled'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
