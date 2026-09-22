<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOptimisticLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Approval extends Model
{
    use BelongsToTenant, HasOptimisticLock, LogsActivity;

    protected $fillable = [
        'approvable_type', 'approvable_id', 'project_id',
        'requested_by_user_id', 'client_user_id',
        'status', 'version', 'comment', 'variants', 'chosen_variant',
        'respond_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'variants' => 'array',
            'respond_by' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Eyni obyektin bütün göndəriş dövrləri — Roomix-dəki «Approval history».
     *
     * Hər yeni göndəriş ayrıca sətirdir (köhnəsi `draft`-a keçir), ona görə
     * tarixçə elə sətirlərin özüdür; burada yalnız düzgün sıralanır.
     */
    public function history(): Builder
    {
        return static::query()
            ->where('approvable_type', $this->approvable_type)
            ->where('approvable_id', $this->approvable_id)
            ->whereKeyNot($this->getKey())
            ->orderByDesc('version');
    }

    /** Roomix: «Client thinking for N days» — göndərişdən bəri keçən tam gün. */
    public function daysWaiting(): int
    {
        return $this->status === ApprovalStatus::Pending
            ? (int) $this->created_at->startOfDay()->diffInDays(now()->startOfDay())
            : 0;
    }

    /** Cavab müddəti keçibsə dizayner üçün siqnal olmalıdır. */
    public function isOverdue(): bool
    {
        return $this->status === ApprovalStatus::Pending
            && $this->respond_by !== null
            && $this->respond_by->isPast();
    }

    /** Çoxvariantlı razılaşdırma: müştəri birini seçməlidir. */
    public function hasVariants(): bool
    {
        return is_array($this->variants) && count($this->variants) > 1;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'comment', 'decided_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    /** Human-readable label of what is being approved. */
    public function subjectLabel(): string
    {
        return match (true) {
            $this->approvable instanceof BudgetLine => 'Smeta: '.$this->approvable->work_type,
            $this->approvable instanceof ProcurementItem => 'Komplektasiya: '.$this->approvable->name,
            $this->approvable instanceof Stage => 'Mərhələ: '.$this->approvable->name,
            $this->approvable instanceof Document => 'Sənəd: '.$this->approvable->title,
            $this->approvable instanceof Deliverable => 'Dizayn: '.$this->approvable->title,
            default => 'Obyekt #'.$this->approvable_id,
        };
    }
}
