<?php

namespace App\Enums;

/**
 * Material kartının KATEQORİYASI (TZ «Layihə naviqasiyası» §5): materialın
 * növü — planlar, vizuallaşdırmalar, çertyojlar, fotolar, sənədlər…
 *
 * Ayrıca `category` sütunu YOXDUR: `deliverables.type` elə kateqoriyadır.
 * Köhnə dizayn tipləri (muudbord, konsepsiya…) olduğu kimi qalır, köçürülən
 * fayl/sənəd növləri üçün `photo`, `document`, `link` əlavə olunub.
 *
 * Kateqoriya mərhələ DEYİL: mərhələ materialın hansı işə aid olduğunu bildirir
 * (`deliverables.stage_id`), kateqoriya isə nə olduğunu.
 */
enum DeliverableType: string
{
    case Moodboard = 'moodboard';
    case Concept = 'concept';
    case Layout = 'layout';
    case Visualization = 'visualization';
    case Drawing = 'drawing';
    case Specification = 'specification';
    case MaterialSelection = 'material_selection';
    case FurnitureSelection = 'furniture_selection';
    case LightingSelection = 'lighting_selection';
    case Photo = 'photo';
    case Document = 'document';
    case Link = 'link';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Moodboard => 'Muudbord',
            self::Concept => 'Konsepsiya',
            self::Layout => 'Planlaşdırma',
            self::Visualization => 'Vizualizasiya',
            self::Drawing => 'Çertyoj',
            self::Specification => 'Spesifikasiya',
            self::MaterialSelection => 'Material seçimi',
            self::FurnitureSelection => 'Mebel seçimi',
            self::LightingSelection => 'İşıqlandırma seçimi',
            self::Photo => 'Fotoşəkil',
            self::Document => 'Sənəd',
            self::Link => 'Keçid',
            self::Custom => 'Digər',
        };
    }

    /**
     * Yükləmə formasında və süzgəcdə göstərilən sıra: ən çox işlənənlər öndə.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $order = [
            self::Layout, self::Visualization, self::Drawing, self::Photo, self::Document,
            self::Moodboard, self::Concept, self::Specification,
            self::MaterialSelection, self::FurnitureSelection, self::LightingSelection,
            self::Link, self::Custom,
        ];

        return collect($order)->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }

    /** Köhnə «Fayllar» tabının kateqoriyası → material kateqoriyası. */
    public static function fromFileCategory(?string $category): self
    {
        return match ($category) {
            FileCategory::Image->value => self::Photo,
            FileCategory::Plan->value => self::Layout,
            FileCategory::Visualization->value => self::Visualization,
            FileCategory::Link->value => self::Link,
            default => self::Custom,
        };
    }

    /** Portalın «Fayllar» səhifəsindəki çip (media / files / links / docs). */
    public function portalFilter(): string
    {
        return match ($this) {
            self::Photo, self::Visualization, self::Moodboard, self::Concept => 'media',
            self::Link => 'links',
            self::Layout, self::Drawing, self::Specification, self::Document => 'docs',
            default => 'files',
        };
    }

    /** Portal ikonunun açarı (`portal/files.blade.php`). */
    public function portalIcon(): string
    {
        return match ($this->portalFilter()) {
            'media' => 'image',
            'links' => 'link',
            'docs' => 'plan',
            default => 'other',
        };
    }
}
