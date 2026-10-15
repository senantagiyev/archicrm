<?php

namespace App\Support;

use App\Models\DeliverableVersion;
use App\Services\Design\MaterialService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Material versiyasının faylını verir — həm heyət, həm portal marşrutu üçün.
 *
 * Avtorizasiya ÇAĞIRANDADIR (policy / müştərinin dərc edilmiş versiyası); bu
 * sinif yalnız indeksi yoxlayır, yolu təhlükəsiz oxuyur və adı təmizləyir.
 */
class MaterialFiles
{
    public static function download(DeliverableVersion $version, int $index): StreamedResponse
    {
        $file = $version->files()[$index] ?? null;

        abort_if($file === null, 404, 'Fayl tapılmadı.');
        abort_unless(self::readable($file['path']), 404, 'Fayl tapılmadı.');

        return Storage::disk(MaterialService::DISK)->download($file['path'], self::filename($file['name'], $file['path']));
    }

    /**
     * Yol diskdə oxunaqlıdırmı — İSTİSNA ATMADAN: disk kökündən kənara çıxan
     * yol (`../../.env`) Flysystem-də `PathTraversalDetected` atır, bu da
     * 404 yerinə 500 olardı (bax `Portal\FileController::readableOnPublicDisk`).
     */
    public static function readable(?string $path): bool
    {
        if (blank($path)) {
            return false;
        }

        try {
            return Storage::disk(MaterialService::DISK)->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    private static function filename(string $name, string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $base = Str::of(pathinfo($name, PATHINFO_FILENAME))->ascii()->replaceMatches('/[^A-Za-z0-9 _-]/', '')->trim();

        return ($base->isEmpty() ? 'file' : $base).($ext ? '.'.$ext : '');
    }
}
