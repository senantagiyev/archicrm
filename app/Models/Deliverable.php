<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\DeliverableStatus;
use App\Enums\DeliverableType;
use App\Enums\MaterialApprovalState;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOptimisticLock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Material kartı («Fayllar» bölməsi, TZ «Layihə naviqasiyası» §5–6).
 *
 * `type` = materialın kateqoriyası, `stage_id` = aid olduğu mərhələ (boş ola
 * bilər — ümumi material), versiyalar `deliverable_versions`-dadır.
 *
 * `published_version_id` — müştəri YALNIZ bu versiyanı görür. Yeni daxili
 * versiya onu avtomatik əvəz etmir. `client_visible` bu sütunun güzgüsüdür
 * (köhnə oxucular üçün) və `saving` hook-unda sinxron saxlanılır.
 */
class Deliverable extends Model
{
    use BelongsToTenant, HasFactory, HasOptimisticLock, LogsActivity, SoftDeletes;

    protected $fillable = [
        'project_id', 'stage_id', 'type', 'title', 'description',
        'current_version_id', 'status', 'responsible_user_id', 'client_visible',
    ];

    protected function casts(): array
    {
        return [
            'type' => DeliverableType::class,
            'status' => DeliverableStatus::class,
            'client_visible' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Dərc vəziyyətinin iki yazıcısı var: yeni `published_version_id` və köhnə
        // `client_visible` (razılaşdırma servisi, köhnə cədvəl). Hansı dəyişirsə,
        // digəri ona uyğunlaşır — əks halda «müştəriyə görünür» işarəsi olan,
        // amma heç bir versiyası müştəriyə açılmayan kart qalardı.
        static::saving(function (self $deliverable): void {
            if ($deliverable->isDirty('published_version_id')) {
                $deliverable->client_visible = $deliverable->published_version_id !== null;

                return;
            }

            if ($deliverable->isDirty('client_visible')) {
                $deliverable->published_version_id = $deliverable->client_visible
                    ? ($deliverable->published_version_id ?? $deliverable->current_version_id)
                    : null;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'type', 'stage_id', 'status', 'current_version_id', 'published_version_id', 'client_visible'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DeliverableVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DeliverableVersion::class, 'current_version_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(DeliverableVersion::class, 'published_version_id');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    /** Bu materiala köçürülmüş köhnə «Fayllar» sətirləri (silinmir). */
    public function legacyFiles(): HasMany
    {
        return $this->hasMany(ProjectFile::class, 'migrated_deliverable_id');
    }

    /** Bu materiala köçürülmüş köhnə «Sənədlər» sətirləri (silinmir). */
    public function legacyDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'migrated_deliverable_id');
    }

    public function isPublished(): bool
    {
        return $this->published_version_id !== null;
    }

    /**
     * Razılaşdırma tarixçəsi: materialın öz sorğuları + köçürülmədən əvvəl
     * mənbə sənədə verilmiş sorğular (onlar yerində qalır, tarixçə itmir).
     * Geri çəkilmiş (`draft`) dövrlər də göstərilir — tarixçədir.
     *
     * @return Collection<int, Approval>
     */
    public function approvalHistory(): Collection
    {
        $own = $this->approvals()->with(['requestedBy', 'clientUser', 'deliverableVersion'])->get();

        $legacyDocumentIds = $this->legacyDocuments()->pluck('id');

        $legacy = $legacyDocumentIds->isEmpty()
            ? collect()
            : Approval::query()
                ->where('approvable_type', (new Document)->getMorphClass())
                ->whereIn('approvable_id', $legacyDocumentIds)
                ->with(['requestedBy', 'clientUser'])
                ->get();

        return $own->concat($legacy)->sortByDesc('id')->values();
    }

    /**
     * Kartdakı razılaşdırma statusu — CARİ versiyaya görə.
     *
     * Yeni versiya köhnənin təsdiqini miras almır: cari versiya üçün sorğu
     * yoxdursa status «Göndərilməyib»dir, hətta əvvəlki versiya təsdiqlənmiş
     * olsa da (o qərar tarixçədə qalır).
     */
    public function approvalState(): MaterialApprovalState
    {
        if ($this->current_version_id === null) {
            return MaterialApprovalState::NotSent;
        }

        $latest = $this->approvalsLoaded()
            ->where('deliverable_version_id', $this->current_version_id)
            ->reject(fn (Approval $approval) => $approval->status === ApprovalStatus::Draft)
            ->sortByDesc('id')
            ->first();

        return MaterialApprovalState::fromApprovalStatus($latest?->status);
    }

    /** Ən azı bir versiyası müştəri tərəfindən təsdiqlənibmi (silmə kilidi). */
    public function hasApprovedVersion(): bool
    {
        if ($this->approvalsLoaded()->contains(fn (Approval $approval) => $approval->status === ApprovalStatus::Approved)) {
            return true;
        }

        // Cədvəl bunu `withExists()` ilə əvvəlcədən yükləyir (hər sətrə sorğu getməsin).
        return array_key_exists('has_immutable_version', $this->attributes)
            ? (bool) $this->attributes['has_immutable_version']
            : $this->versions()->whereIn('status', ['approved', 'locked'])->exists();
    }

    /**
     * Müştərinin aça biləcəyi versiyalar: dərc edilmiş versiya + ona
     * razılaşdırmaya göndərilmiş versiyalar (cavab gözləyən və ya qərar
     * verilmiş). Daxili versiyalar heç bir yolla (link, id) açılmır.
     *
     * @return array<int, int>
     */
    public function clientVersionIds(): array
    {
        $sent = $this->approvals()
            ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value])
            ->whereNotNull('deliverable_version_id')
            ->pluck('deliverable_version_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_filter([
            $this->published_version_id ? (int) $this->published_version_id : null,
            ...$sent,
        ])));
    }

    /** @return Collection<int, Approval> */
    private function approvalsLoaded(): Collection
    {
        if (! $this->relationLoaded('approvals')) {
            $this->load('approvals');
        }

        return $this->approvals;
    }
}
