<?php

namespace App\Models;

use App\Enums\AutomationPriority;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AutomationRule extends Model
{
    use LogsActivity;

    protected $fillable = [
        'code', 'name', 'trigger', 'priority', 'enabled', 'conditions', 'actions',
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
