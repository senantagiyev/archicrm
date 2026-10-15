<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Polimorf şərh (hazırda material versiyalarına).
 *
 * `is_internal` — daxili şərh müştəriyə HEÇ YERDƏ çıxmır (portal, ixrac,
 * hadisə lenti). Defolt daxilidir: müştəriyə açmaq açıq qərardır.
 */
class Comment extends Model
{
    protected $fillable = ['commentable_type', 'commentable_id', 'author_type', 'author_id', 'body', 'is_internal'];

    protected $attributes = [
        'is_internal' => true,
    ];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    /** Müştərinin görə biləcəyi şərhlər. */
    public function scopeForClient(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
