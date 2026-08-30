<?php

use App\Models\Setting;

if (! function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }
}

if (! function_exists('t')) {
    /**
     * Translate a UI string from the database-backed translations
     * (Filament "Tərcümələr" module). Signature-compatible with __():
     *   t('common.app_name')
     *   t('tasks.deadline_in_days', ['days' => 3])
     *   t('nav.projects', [], 'ru')
     * Returns a string for scalar keys, an array for grouped/list keys,
     * or the key itself when nothing matches.
     */
    function t(string $key, array $replace = [], ?string $locale = null): array|string
    {
        return __($key, $replace, $locale);
    }
}

if (! function_exists('storage_url')) {
    /**
     * Resolve an uploaded file path from the DB to a public URL.
     * Uploads go to the public disk (storage/app/public/) and need the /storage/ prefix.
     */
    function storage_url(?string $path, string $fallback = ''): string
    {
        if (! $path) {
            return $fallback;
        }
        if (str_starts_with($path, '/') || str_starts_with($path, 'http')) {
            return $path;
        }

        return asset('storage/'.$path);
    }
}
