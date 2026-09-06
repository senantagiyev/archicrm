<?php

namespace App\Models;

use App\Enums\DeliverableVersionStatus;
use App\Models\Concerns\HasOptimisticLock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class DeliverableVersion extends Model
{
    use HasFactory, HasOptimisticLock;

    protected $fillable = [
        'deliverable_id', 'version_number', 'file_path',
        'created_by_user_id', 'status', 'change_summary', 'based_on_version_id', 'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliverableVersionStatus::class,
            'locked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // TZ §7.12/§9.2: an approved/locked version is immutable — no edit/replace/delete.
        // Only status→locked (the lock transition itself) and the immutable audit
        // fields may still be written; everything else is blocked.
        static::updating(function (self $version): void {
            $original = $version->getOriginal('status');
            $wasImmutable = $original instanceof DeliverableVersionStatus
                ? $original->isImmutable()
                : DeliverableVersionStatus::tryFrom((string) $original)?->isImmutable();

            if (! $wasImmutable) {
                return;
            }

            // Allow only the approved → locked transition (+ its locked_at stamp).
            // row_version is an infra counter (optimistic lock), not domain data.
            $dirty = array_keys($version->getDirty());
            $allowed = ['status', 'locked_at', 'updated_at', 'row_version'];
            if (array_diff($dirty, $allowed) !== []
                || ($version->status !== DeliverableVersionStatus::Locked)) {
                throw new RuntimeException('Təsdiqlənmiş versiya dəyişdirilə bilməz — yalnız yeni versiya yaradın.');
            }
        });

        static::deleting(function (self $version): void {
            $status = $version->status;
            if ($status instanceof DeliverableVersionStatus && $status->isImmutable()) {
                throw new RuntimeException('Təsdiqlənmiş versiya silinə bilməz.');
            }
        });
    }

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Guarded state transition (TZ §9.2). Illegal transitions throw — the
     * controller/Filament layer surfaces this as a 409 with allowed_transitions.
     */
    public function transitionTo(DeliverableVersionStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new RuntimeException(
                'Qadağan keçid: '.$this->status->value.' → '.$to->value.
                '. İcazəli: '.implode(', ', DeliverableVersionStatus::allowed()[$this->status->value] ?? [])
            );
        }

        $this->status = $to;
        if ($to === DeliverableVersionStatus::Locked) {
            $this->locked_at = now();
        }
        $this->save();
    }
}
