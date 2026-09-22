<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Müəllif nəzarəti gündəliyi: obyektdən qısa qeyd + fotolar.
 * Dizayner qaralama yazır, «Dərc et» ilə müştəriyə açır.
 */
class DiaryEntry extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'project_id', 'author_user_id', 'body', 'photos', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** Müştəri portalında yalnız dərc olunmuş qeydlər görünür. */
    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
