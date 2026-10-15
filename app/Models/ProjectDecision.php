<?php

namespace App\Models;

use App\Enums\DecisionSource;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProjectDecision extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity;

    protected $fillable = [
        'project_id',
        'title',
        'category',
        'source',
        'related_entity_type',
        'related_entity_id',
        'decision',
        'made_by_user_id',
        'decided_at',
        'client_approved',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'source' => DecisionSource::class,
            'decided_at' => 'datetime',
            'client_approved' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'source', 'decision', 'client_approved', 'decided_at', 'related_entity_type', 'related_entity_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Qərarın aid olduğu obyekt: material, mərhələ və ya irad (`ChangeSubjects`).
     * Köhnə qeydlərdə boşdur — onlar İcmaldakı «Yoxlanmalı qeydlər»də görünür.
     */
    public function relatedEntity(): MorphTo
    {
        return $this->morphTo('relatedEntity', 'related_entity_type', 'related_entity_id');
    }

    /** Yalnız bu obyektə bağlı qərarlar. */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where($query->qualifyColumn('related_entity_type'), $subject->getMorphClass())
            ->where($query->qualifyColumn('related_entity_id'), $subject->getKey());
    }

    /** Heç bir obyektə bağlanmamış (köhnə) qərarlar — avtomatik bölüşdürülmür. */
    public function scopeUnlinked(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereNull($query->qualifyColumn('related_entity_type'))
            ->orWhereNull($query->qualifyColumn('related_entity_id')));
    }

    public function madeBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by_user_id');
    }
}
