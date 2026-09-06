<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Lead extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'company', 'phone', 'email', 'whatsapp', 'telegram',
        'lead_source', 'responsible_user_id', 'status', 'estimated_project_type',
        'estimated_area', 'estimated_budget', 'first_contact_date', 'next_follow_up_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'estimated_area' => 'decimal:2',
            'estimated_budget' => 'decimal:2',
            'first_contact_date' => 'date',
            'next_follow_up_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['first_name', 'last_name', 'status', 'responsible_user_id', 'phone', 'email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
