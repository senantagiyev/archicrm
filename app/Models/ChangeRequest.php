<?php

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ChangeRequest extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity;

    protected $fillable = [
        'project_id', 'number', 'requested_by', 'requested_by_user_id',
        'title', 'description', 'reason', 'affected_entity_type', 'affected_entity_id',
        'schedule_impact_days', 'cost_impact', 'estimated_hours',
        'responsible_user_id', 'status',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'status' => ChangeRequestStatus::class,
            'cost_impact' => 'decimal:2',
            'estimated_hours' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $cr): void {
            if (blank($cr->number)) {
                // Derived from the highest number issued, not from count(): with
                // count(), deleting CR-7-002 made the next create re-issue
                // CR-7-003 on top of the live one.
                // `orderByDesc('id')` sətirin yalnız SONUNCU QALANINI görürdü:
                // aradan bir CR silinəndə növbəti yazı diri sətrin nömrəsini
                // təkrar verirdi. Nömrə sətir adının içində saxlandığına görə ən
                // böyüyünü SQL-də yox, sonluğu ayıraraq tapırıq — bir layihədə
                // CR sayı azdır. (Sonuncu nömrə silinərsə, o nömrə yenidən
                // işlənir; tam monoton sayğac ayrıca sütun tələb edir.)
                $seq = 1 + (int) static::withoutGlobalScopes()
                    ->where('project_id', $cr->project_id)
                    ->pluck('number')
                    ->map(fn ($number) => preg_match('/(\d+)$/', (string) $number, $m) ? (int) $m[1] : 0)
                    ->max();

                $cr->number = 'CR-'.$cr->project_id.'-'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'title', 'schedule_impact_days', 'cost_impact'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** Guarded transition (TZ §9.3) — illegal transitions throw (409-style). */
    public function transitionTo(ChangeRequestStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new RuntimeException(
                'Qadağan keçid: '.$this->status->value.' → '.$to->value.
                '. İcazəli: '.implode(', ', ChangeRequestStatus::allowed()[$this->status->value] ?? [])
            );
        }

        $this->status = $to;
        $this->save();
    }
}
