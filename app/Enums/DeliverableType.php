<?php

namespace App\Enums;

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
            self::Custom => 'Digər',
        };
    }
}
