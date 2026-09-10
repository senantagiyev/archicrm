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

    protected $fillable = ['key', 'level', 'name', 'description', 'is_default', 'active', 'position'];

    public array $translatable = ['name', 'description'];

    /** Spec Part 8.1 — the two brief levels. */
    public const LEVEL_QUICK = 'quick';

    public const LEVEL_PREMIUM = 'premium';

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'active' => 'boolean'];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(BriefSection::class)->orderBy('position');
    }

    public function isQuick(): bool
    {
        return $this->level === self::LEVEL_QUICK;
    }

    /** Human label for the level, used wherever the studio picks a template. */
    public function levelLabel(): string
    {
        return $this->isQuick() ? 'Quick Brief (~3 dəq)' : 'Premium Brief';
    }

    /**
     * The template a new brief defaults to. Never a Quick Brief: the short
     * questionnaire is something the studio switches to deliberately, so an
     * unattended project always opens on the full one.
     */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->where('level', self::LEVEL_PREMIUM)->first()
            ?? static::query()->where('active', true)->where('level', self::LEVEL_PREMIUM)->orderBy('position')->first();
    }
}
