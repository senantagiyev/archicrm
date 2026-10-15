<?php

namespace App\Models;

use App\Enums\DeliverableVersionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOptimisticLock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Material kartının versiyası. Bir versiyada bir neçə əlaqəli fayl ola bilər
 * (`attachments`); `file_path` birinci faylı təkrarlayır ki, köhnə oxucular
 * sınmasın. Fayllar yalnız avtorizasiyalı marşrutla verilir
 * (`materials.download` / `portal.materials.download`).
 */
class DeliverableVersion extends Model
{
    use BelongsToTenant, HasFactory, HasOptimisticLock;

    protected $fillable = [
        'deliverable_id', 'version_number', 'file_path', 'attachments',
        'created_by_user_id', 'uploaded_by_type', 'uploaded_by_id',
        'status', 'change_summary', 'based_on_version_id', 'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliverableVersionStatus::class,
            'attachments' => 'array',
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

    /** Müəllif: heyət (`user`) və ya müştəri (`client_user`). */
    public function uploadedBy(): MorphTo
    {
        return $this->morphTo('uploaded_by');
    }

    /** Konkret versiyaya şərhlər; daxili olanlar müştəriyə çıxmır. */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->oldest('id');
    }

    public function isClientUploaded(): bool
    {
        return $this->uploaded_by_type === (new ClientUser)->getMorphClass();
    }

    /**
     * Versiyanın faylları sıra ilə. `attachments` boşdursa (köhnə versiya)
     * yeganə fayl `file_path`-dır.
     *
     * @return array<int, array{path: string, name: string, size: int|null, mime: string|null}>
     */
    public function files(): array
    {
        $files = collect($this->attachments ?? [])
            ->filter(fn ($file) => is_array($file) && filled($file['path'] ?? null))
            ->map(fn (array $file) => [
                'path' => (string) $file['path'],
                'name' => filled($file['name'] ?? null) ? (string) $file['name'] : basename((string) $file['path']),
                'size' => isset($file['size']) ? (int) $file['size'] : null,
                'mime' => $file['mime'] ?? null,
            ])
            ->values()
            ->all();

        if ($files === [] && filled($this->file_path)) {
            $files[] = ['path' => $this->file_path, 'name' => basename($this->file_path), 'size' => null, 'mime' => null];
        }

        return $files;
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
