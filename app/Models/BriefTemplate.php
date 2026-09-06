<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * A named brief question-set (TZ §8.8) — e.g. residential vs commercial. The
 * studio picks one per project; the wizard shows only that template's sections.
 */
class BriefTemplate extends Model
{
    use HasTranslations;

    protected $fillable = ['key', 'name', 'description', 'is_default', 'active', 'position'];

    public array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'active' => 'boolean'];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(BriefSection::class)->orderBy('position');
    }

    /** The template a new brief defaults to. */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->where('active', true)->orderBy('position')->first();
    }
}
