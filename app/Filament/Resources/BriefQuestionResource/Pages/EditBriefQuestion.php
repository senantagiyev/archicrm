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
     * `options` sahəsi formaya BİRBAŞA bağlanmır, iki köməkçi açara açılır:
     *   • `option_cards` — adi sualda `options` özü variant SİYAHISIDIR;
     *   • `option_rows`  — `std_or_custom` sualında variantlar `options.items`
     *     altındadır, `options` isə siyahı yox, KONFİQDİR.
     *
     * Niyə köməkçi açar: əvvəl iki repeater eyni `options` yolunu paylaşırdı
     * (`options` və `options.items`). Filament gizli bölmənin komponentlərini də
     * hidratlaşdırdığı üçün `std_or_custom` sualında birinci repeater bütün
     * `items` siyahısını BİR sətrin içinə yığırdı, ikinci repeater isə sətirsiz
     * qalırdı: admin sətirləri görmür, yüklədiyi şəkil diskə düşüb bazaya
     * düşmürdü. Ayrı açarlar bu toqquşmanı kökündən aradan qaldırır.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $options = $this->getRecord()->options;
        $options = is_array($options) ? $options : [];

        if ($this->getRecord()->type === 'std_or_custom') {
            $data['option_rows'] = array_values((array) ($options['items'] ?? []));
            $data['option_cards'] = [];
        } else {
            $data['option_cards'] = array_is_list($options) ? $options : [];
            $data['option_rows'] = [];
        }

        // Formada `options` adlı komponent yoxdur; onu state-də saxlamaq brauzerə
        // lazımsız məlumat göndərmək olardı.
        unset($data['options']);

        return $data;
    }

    /**
     * Köməkçi açarları geri `options`-a yığır.
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
        $record = $this->getRecord();
        $stored = is_array($record->options) ? $record->options : [];

        if ($record->type === 'std_or_custom') {
            $rows = $this->cleanOptions(array_values((array) ($data['option_rows'] ?? [])));

            // Sətirlər formada nə əlavə, nə də silinə bilir (`addable(false)`,
            // `deletable(false)`), ona görə boş nəticə yalnız form vəziyyətinin
            // itməsi deməkdir — belə halda saxlanılmış sətirlər qorunur.
            $stored['items'] = $rows !== [] ? $rows : array_values((array) ($stored['items'] ?? []));
            $data['options'] = $stored;
        } elseif ($stored === [] || array_is_list($stored)) {
            $cards = $this->cleanOptions(array_values((array) ($data['option_cards'] ?? [])));

            // Eyni qoruyucu: variantlar paneldən silinə bilmir, deməli boş nəticə
            // yalnız itirilmiş form vəziyyətidir.
            $data['options'] = $cards !== [] ? $cards : $stored;
        }
        // matrix / color_swatch / budget_range kimi konfiq formasında `options`
        // ümumiyyətlə toxunulmur — orada nə şəkil var, nə dəyişiləsi sətir.

        unset($data['option_cards'], $data['option_rows']);

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
