<?php

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * Locale-aware label for enums shown in the client portal. Falls back to the
 * hardcoded AZ label() when the DB translation is missing.
 */
trait TranslatesLabels
{
    public function translatedLabel(): string
    {
        $key = 'enums.'.Str::snake(class_basename(static::class)).'.'.$this->value;
        $translated = t($key);

        return is_string($translated) && $translated !== $key ? $translated : $this->label();
    }
}
