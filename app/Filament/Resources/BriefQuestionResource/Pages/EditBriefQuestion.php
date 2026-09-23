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
     * Formadakı İKİ repeater eyni `options` açarını paylaşır və onların saxladığı
     * quruluş eyni deyil:
     *   • adi sual — `options` özü variant SİYAHISIDIR;
     *   • `std_or_custom` — variantlar `options.items` altındadır, `options` isə
     *     siyahı yox, KONFİQDİR.
     * Ona görə format sualın tipindən müəyyən edilir. Əvvəllər hər iki hal
     * şərtsiz `array_values()`-dan keçirdi: bu, konfiqi siyahıya çevirərək
     * sətirləri (standart ölçü, vahid, yüklənmiş şəkillər) tamamilə silirdi, adi
     * sualda isə gizli repeater-in qaytardığı boş sətirləri siyahıya yapışdırıb
     * hər saxlanmada seçilə bilməyən boş kart əlavə edirdi.
     *
     * Repeater boş fayl sahələrini `null` / `[]` kimi qaytarır. Onları variantın
     * içində saxlasaq, `filled()` yoxlamaları «şəkil var» sayardı və frontendə
     * boş modal açan ikon düşərdi — ona görə boş açarlar silinir.
     *
     * Variantın `value`/`label`-ı toxunulmur: şəkil yanlış varianta bağlanmasın
     * deyə onlar formada gizli və dəyişməzdir.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! isset($data['options']) || ! is_array($data['options'])) {
            return $data;
        }

        if ($this->getRecord()->type === 'std_or_custom') {
            $options = $data['options'];
            $items = $this->cleanOptions((array) ($options['items'] ?? []));

            // Sətirlər formada nə əlavə, nə də silinə bilir (`addable(false)`,
            // `deletable(false)`), ona görə boş nəticə yalnız form vəziyyətinin
            // itməsi deməkdir — belə halda saxlanılmış sətirlər qorunur.
            $options['items'] = $items !== [] ? $items : (array) ($this->getRecord()->options['items'] ?? []);
            $data['options'] = $options;

            return $data;
        }

        // Adi sualda `items` açarı yad qonaqdır — gizli repeater-dən düşür.
        unset($data['options']['items']);

        $data['options'] = $this->cleanOptions($data['options']);

        return $data;
    }

    /**
     * @param  array<mixed>  $options
     * @return array<int, mixed>
     */
    private function cleanOptions(array $options): array
    {
        $cleaned = array_map(function ($option) {
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
        }, $options);

        // Nə `value`, nə `label` daşıyan sətir variant deyil — portalda boş kart
        // kimi görünərdi.
        return array_values(array_filter(
            $cleaned,
            fn ($option) => ! is_array($option) || filled($option['value'] ?? null) || filled($option['label'] ?? null),
        ));
    }
}
