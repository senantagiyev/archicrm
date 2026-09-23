<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource\RelationManagers\DiaryRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\FilesRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\ProcurementItemsRelationManager;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Yükləyən və oxuyan eyni diskdə olmalıdır.
 *
 * Filament-in `FileUpload` sahəsi disk göstərilmədikdə `FILESYSTEM_DISK`
 * defoltunu götürür; Laravel 12-də bu `local`-dır və fayl `storage/app/private`
 * altına düşür. Tətbiqin BÜTÜN oxuma yolları isə (`Storage::disk('public')`,
 * `storage_url()`) `public` diskindədir. Disk yazılmadığı üçün paneldən
 * yüklənən hər fayl — sənəd, layihə faylı, gündəlik fotosu, komplektasiya
 * fotosu, brif variant şəkli — müştəri portalında 404/500 verirdi.
 *
 * Bu testlər həmin uyğunsuzluğun qayıtmasının qarşısını alır: biri formaların
 * faktiki vəziyyətini Filament-in öz obyektlərindən oxuyur, digəri isə mənbə
 * kodunda disksiz qalmış `FileUpload` axtarır (forma qurmaq tələb etməyən,
 * ucuz və bütün resursları əhatə edən yoxlama).
 */
class UploadDiskTest extends TestCase
{
    private const EXPECTED_DISK = 'public';

    /**
     * Mənbə kodunda hər `FileUpload::make(...)` zənciri `->disk(...)` daşımalıdır.
     */
    public function test_every_filament_file_upload_declares_a_disk(): void
    {
        $missing = [];

        foreach (File::allFiles(app_path('Filament')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = $file->getContents();

            // Zənciri `FileUpload::make(` ilə başlayıb sonrakı `,` və ya `]`-də
            // bitən blok kimi götürürük — sahə tərifi bir ifadədir.
            preg_match_all('/FileUpload::make\((.*?)\)(.*?)(?=\n\s*(?:Forms\\\\Components\\\\|Tables\\\\|\]\)|\];))/s', $contents, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (! str_contains($match[2], '->disk(')) {
                    $missing[] = $file->getRelativePathname().' → FileUpload::make('.trim($match[1]).')';
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Bu sahələrdə disk göstərilməyib — fayl `storage/app/private`-a düşəcək və portalda açılmayacaq:\n".implode("\n", $missing),
        );
    }

    /**
     * Sahənin faktiki diski `public` olmalıdır: kodda `->disk()` olsa da yanlış
     * diski göstərə bilər, bu yoxlama Filament-in özündən oxuyur.
     *
     * @param  class-string  $class
     */
    #[DataProvider('formOwners')]
    public function test_file_upload_fields_point_at_the_public_disk(string $class, string $method): void
    {
        $components = $this->fileUploadsOf($class, $method);

        $this->assertNotEmpty($components, $class.' üçün heç bir FileUpload tapılmadı — test köhnəlib.');

        foreach ($components as $component) {
            $this->assertSame(
                self::EXPECTED_DISK,
                $component->getDiskName(),
                $class.'::'.$component->getName().' yanlış diskdədir.',
            );
        }
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function formOwners(): array
    {
        return [
            'Sənədlər' => [DocumentsRelationManager::class, 'form'],
            'Fayllar' => [FilesRelationManager::class, 'form'],
            'Gündəlik' => [DiaryRelationManager::class, 'form'],
            'Komplektasiya' => [ProcurementItemsRelationManager::class, 'form'],
        ];
    }

    /**
     * @param  class-string  $class
     * @return array<int, FileUpload>
     */
    private function fileUploadsOf(string $class, string $method): array
    {
        $schema = (new \ReflectionMethod($class, $method))->invoke(
            (new \ReflectionClass($class))->newInstanceWithoutConstructor(),
            new Schema,
        );

        return collect($schema->getComponents())
            ->flatMap(fn ($component) => $component instanceof FileUpload
                ? [$component]
                : collect($component->getChildComponents ?? [])->all())
            ->filter(fn ($component) => $component instanceof FileUpload)
            ->values()
            ->all();
    }
}
