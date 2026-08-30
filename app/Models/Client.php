<?php

namespace App\Models;

use App\Enums\ClientSource;
use App\Enums\ClientStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Client extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'company', 'phone', 'whatsapp', 'telegram', 'email',
        'source', 'status', 'first_contact_at', 'responsible_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'source' => ClientSource::class,
            'first_contact_at' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'responsible_user_id', 'phone', 'email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function contactLogs(): HasMany
    {
        return $this->hasMany(ClientContactLog::class)->latest('contacted_at');
    }

    public function clientUsers(): HasMany
    {
        return $this->hasMany(ClientUser::class);
    }
}
