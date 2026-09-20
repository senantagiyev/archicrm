<?php

namespace App\Filament\Resources\BriefQuestionResource\Pages;

use App\Filament\Resources\BriefQuestionResource;
use Filament\Resources\Pages\EditRecord;

class EditBriefQuestion extends EditRecord
{
    protected static string $resource = BriefQuestionResource::class;

    /** Silmə yoxdur — sual bankın nəzarətindədir. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Repeater boş fayl sahələrini `null` / `[]` kimi qaytarır. Onları
     * variantın içində saxlasaq, `filled()` yoxlamaları «şəkil var» sayardı və
     * frontendə boş modal açan ikon düşərdi — ona görə boş açarlar silinir.
     *
     * Variantın `value`/`label`-ı toxunulmur: şəkil yanlış varianta bağlanmasın
     * deyə onlar formada gizli və dəyişməzdir.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! isset($data['options']) || ! is_array($data['options'])) {
            return $data;
        }

        $data['options'] = array_values(array_map(function ($option) {
            if (! is_array($option)) {
                return $option;
            }

            if (blank($option['image_url'] ?? null)) {
                unset($option['image_url']);
            }

            $images = array_values(array_filter((array) ($option['images'] ?? [])));
            if ($images === []) {
                unset($option['images']);
            } else {
                $option['images'] = $images;
            }

            return $option;
        }, $data['options']));

        return $data;
    }
}
