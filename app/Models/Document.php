<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Document extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity;

    protected $fillable = [
        'project_id', 'type', 'title', 'file_path', 'size', 'mime',
        'uploaded_by_type', 'uploaded_by_id', 'visible_to_client',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'visible_to_client' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'type', 'visible_to_client'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Portalda görünən sənədlər.
     *
     * Material kartına köçürülmüş sənədin görünürlüyünü artıq material idarə
     * edir (dərc edilmiş versiya): köhnə `visible_to_client` donmuş tarixçədir
     * və materialı dərcdən çıxaran heyətin qərarını üstələməməlidir.
     */
    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where(fn (Builder $q) => $q->whereNull('migrated_deliverable_id')->where('visible_to_client', true))
                ->orWhereIn('migrated_deliverable_id', Deliverable::query()
                    ->withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->whereNotNull('published_version_id')
                    ->select('id'));
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** «Fayllar» material kartı — köhnə linklər bura yönləndirilir. */
    public function migratedDeliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class, 'migrated_deliverable_id');
    }

    public function uploadedBy(): MorphTo
    {
        return $this->morphTo('uploaded_by');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
}
