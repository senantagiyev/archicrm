<?php

namespace App\Models;

use App\Enums\BriefStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brief extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'project_id', 'brief_template_id', 'status', 'progress', 'presented_at',
        'completed_at', 'submitted_at', 'approved_at', 'current_version',
    ];

    protected function casts(): array
    {
        return [
            'presented_at' => 'datetime',
            'completed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'current_version' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(BriefTemplate::class, 'brief_template_id');
    }

    /**
     * Müştəriyə rəsmən göstərilibmi. Portal yalnız təqdim edilmiş brifi
     * göstərir; ona qədər brif studiyanın daxili qaralamasıdır — admin baxa,
     * şablonu dəyişə bilər, müştəri isə heç nə görmür.
     */
    public function isPresented(): bool
    {
        return $this->presented_at !== null;
    }

    public function answers(): HasMany
    {
        return $this->hasMany(BriefAnswer::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(BriefRoom::class)->orderBy('position');
    }

    public function sectionStates(): HasMany
    {
        return $this->hasMany(BriefSectionState::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BriefVersion::class)->orderByDesc('version');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(BriefComment::class)->latest();
    }

    public function openComments(): HasMany
    {
        return $this->hasMany(BriefComment::class)->where('status', 'open');
    }

    public function statusEnum(): BriefStatus
    {
        return BriefStatus::tryFrom((string) $this->status) ?? BriefStatus::Draft;
    }

    /** Read-only for the client (submitted / needs_clarification / approved). */
    public function isLocked(): bool
    {
        return $this->statusEnum()->isLocked();
    }

    /** Kept for existing views/controllers: "completed" now means locked. */
    public function isCompleted(): bool
    {
        return $this->isLocked();
    }

    public function needsClarification(): bool
    {
        return $this->statusEnum() === BriefStatus::NeedsClarification;
    }

    public function isApproved(): bool
    {
        return $this->statusEnum() === BriefStatus::Approved;
    }
}
