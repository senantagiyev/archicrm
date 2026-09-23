<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Translatable\HasTranslations;

class Translation extends Model
{
    use HasTranslations;

    protected $fillable = ['group', 'key', 'value'];

    public array $translatable = ['value'];

    protected static function booted(): void
    {
        // Keep the DatabaseTranslationLoader cache in sync with edits made in the
        // Filament panel (or the seeder). Any change flushes the affected group —
        // including the previous group when a row is moved between groups.
        static::saved(function (self $t): void {
            if ($t->wasChanged('group') && $t->getOriginal('group')) {
                $t->forgetCacheFor($t->getOriginal('group'));
            }
            $t->forgetCache();
        });

        static::deleted(fn (self $t) => $t->forgetCache());
    }

    public function forgetCache(): void
    {
        $this->forgetCacheFor($this->group);
    }

    public function forgetCacheFor(string $group): void
    {
        foreach (['az', 'ru', 'en'] as $locale) {
            Cache::forget("translations.{$group}.{$locale}");
        }

        static::forgetLoadedLines();
    }

    /**
     * Keşi silmək tək başına kifayət etmirdi: Laravel tərcüməçisi oxuduğu
     * qrupları həmin sorğu boyu yaddaşında (`$loaded`) saxlayır və loader-i bir
     * daha çağırmır. Ona görə redaktoru saxlayan Livewire sorğusu KÖHNƏ mətni
     * render edirdi — dəyişiklik yalnız növbəti sorğuda görünürdü və redaktor
     * «getmədi» deyib eyni sətri təkrar-təkrar saxlayırdı.
     */
    private static function forgetLoadedLines(): void
    {
        $translator = app('translator');

        if (method_exists($translator, 'setLoaded')) {
            $translator->setLoaded([]);
        }
    }

    public static function clearCache(): void
    {
        static::forgetLoadedLines();

        foreach (static::distinct()->pluck('group') as $group) {
            foreach (['az', 'ru', 'en'] as $locale) {
                Cache::forget("translations.{$group}.{$locale}");
            }
        }
    }
}
