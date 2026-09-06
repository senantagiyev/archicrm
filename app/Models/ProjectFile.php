<?php

namespace App\Models;

use App\Enums\FileCategory;
use App\Enums\FileVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProjectFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'category', 'visibility', 'title', 'file_path', 'size', 'mime',
        'uploaded_by_type', 'uploaded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => FileCategory::class,
            'visibility' => FileVisibility::class,
        ];
    }

    /** TZ §7.16 — visible in the customer portal. */
    public function scopeClientVisible($query)
    {
        return $query->whereIn('visibility', FileVisibility::clientVisible());
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploadedBy(): MorphTo
    {
        return $this->morphTo('uploaded_by');
    }
}
