<?php

namespace Database\Seeders;

use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use Illuminate\Database\Seeder;

class BriefQuestionBankSeeder extends Seeder
{
    /**
     * Idempotent upsert by key. The residential bank lives in
     * database/seeders/brief/bank.php (versioned in git); a second, focused
     * commercial template is defined inline (TZ §8.8: ≥2 templates). Runtime rows
     * stay editable in Filament without breaking old answers (answers FK to
     * question ids; keys are stable).
     */
    public function run(): void
    {
        $residential = BriefTemplate::updateOrCreate(
            ['key' => 'residential'],
            [
                'name' => ['az' => 'Yaşayış obyekti', 'ru' => 'Жилой объект', 'en' => 'Residential'],
                'description' => ['az' => 'Mənzil və evlər üçün tam brif.'],
                'is_default' => true,
                'active' => true,
                'position' => 1,
            ],
        );

        $commercial = BriefTemplate::updateOrCreate(
            ['key' => 'commercial'],
            [
                'name' => ['az' => 'Kommersiya obyekti', 'ru' => 'Коммерческий объект', 'en' => 'Commercial'],
                'description' => ['az' => 'Ofis, mağaza, restoran kimi obyektlər üçün qısaldılmış brif.'],
                'is_default' => false,
                'active' => true,
                'position' => 2,
            ],
        );

        $bank = require database_path('seeders/brief/bank.php');
        foreach ($bank as $position => $sectionData) {
            $this->upsertSection($residential->id, $position, $sectionData);
        }

        foreach ($this->commercialBank() as $position => $sectionData) {
            $this->upsertSection($commercial->id, $position, $sectionData);
        }
    }

    private function upsertSection(int $templateId, int $position, array $sectionData): void
    {
        $questionCount = count($sectionData['questions']);

        $section = BriefSection::updateOrCreate(
            ['key' => $sectionData['key']],
            [
                'brief_template_id' => $templateId,
                'name' => $sectionData['name'],
                'icon' => $sectionData['icon'] ?? null,
                'room_type' => $sectionData['room_type'] ?? null,
                // TZ §8.8: per-section time estimate. Explicit value wins, else ~20s/question.
                'estimated_minutes' => $sectionData['estimated_minutes'] ?? max(2, (int) ceil($questionCount / 3)),
                'position' => $position,
                'active' => true,
            ],
        );

        foreach ($sectionData['questions'] as $qPosition => $q) {
            // Backward-compatible: [key,label,type,options,required,delegatable, skip_logic?]
            [$key, $label, $type, $options, $required, $delegatable] = $q;
            $skipLogic = $q[6] ?? null;

            BriefQuestion::updateOrCreate(
                ['brief_section_id' => $section->id, 'key' => $key],
                [
                    'label' => is_array($label) ? $label : ['az' => $label],
                    'type' => $type,
                    'options' => $options,
                    'skip_logic' => $skipLogic,
                    'is_required' => $required,
                    'allows_designer_choice' => $delegatable,
                    'position' => $qPosition,
                    'active' => true,
                ],
            );
        }
    }

    /** Focused commercial question-set (distinct keys so it lives beside the residential bank). */
    private function commercialBank(): array
    {
        $opt = fn (array $pairs) => collect($pairs)
            ->map(fn ($label, $value) => ['value' => $value, 'label' => ['az' => $label]])
            ->values()
            ->all();

        return [
            [
                'key' => 'com_object',
                'name' => ['az' => 'Obyekt haqqında', 'ru' => 'Об объекте', 'en' => 'Object'],
                'icon' => 'heroicon-o-building-office',
                'questions' => [
                    ['com_purpose', 'Obyektin təyinatı', 'select', $opt(['office' => 'Ofis', 'retail' => 'Mağaza', 'horeca' => 'Restoran / Kafe', 'clinic' => 'Klinika', 'other' => 'Digər']), true, false],
                    ['com_area', 'Ümumi sahə (m²)', 'number', null, true, false],
                    ['com_floors', 'Mərtəbə sayı', 'number', null, false, false],
                    ['com_capacity', 'Tutum / iş yeri sayı', 'number', null, false, true],
                    ['com_condition', 'Hazırkı vəziyyət', 'select', $opt(['shell' => 'Qara çərçivə', 'fitted' => 'Təmirli', 'operating' => 'Fəaliyyətdə']), true, false],
                ],
            ],
            [
                'key' => 'com_brand',
                'name' => ['az' => 'Üslub və brend', 'ru' => 'Стиль и бренд', 'en' => 'Style & brand'],
                'icon' => 'heroicon-o-swatch',
                'questions' => [
                    ['com_brandbook', 'Brend kitabçası (brandbook) varmı?', 'select', $opt(['yes' => 'Bəli', 'no' => 'Xeyr']), false, false],
                    ['com_style_ref', 'Üslub referansları / gözləntilər', 'textarea', null, false, true],
                    ['com_colors', 'Korporativ rənglər', 'text', null, false, true],
                ],
            ],
            [
                'key' => 'com_zones',
                'name' => ['az' => 'Zonalar və funksiya', 'ru' => 'Зоны и функции', 'en' => 'Zones'],
                'icon' => 'heroicon-o-squares-2x2',
                'questions' => [
                    ['com_zones_list', 'Lazım olan zonalar', 'multiselect', $opt(['reception' => 'Qəbul / resepşn', 'work' => 'İş sahəsi', 'meeting' => 'Toplantı otağı', 'kitchen' => 'Mətbəx / istirahət', 'storage' => 'Anbar', 'sanitary' => 'Sanitar qovşaq']), true, false],
                    ['com_zoning_notes', 'Zonalaşma / axın barədə qeydlər', 'textarea', null, false, true],
                    ['com_notes', 'Əlavə tələblər', 'textarea', null, false, false],
                ],
            ],
        ];
    }
}
