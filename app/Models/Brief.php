<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brief extends Model
{
    use BelongsToTenant;

    protected $fillable = ['project_id', 'brief_template_id', 'status', 'progress', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
