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
        // Spec Part 8.1 — level 1: the ~3 min starter questionnaire used before /
        // right after the contract. Its question keys deliberately mirror the
        // Premium bank so BriefService::switchTemplate() carries the answers over.
        $quick = BriefTemplate::updateOrCreate(
            ['key' => 'quick'],
            [
                'level' => BriefTemplate::LEVEL_QUICK,
                'name' => ['az' => 'Quick Brief', 'ru' => 'Quick Brief', 'en' => 'Quick Brief'],
                'description' => ['az' => 'Layihənin sürətli yaradılması üçün ~3 dəqiqəlik başlanğıc anket.'],
                'is_default' => false,
                'active' => true,
                'position' => 0,
            ],
        );

        $residential = BriefTemplate::updateOrCreate(
            ['key' => 'residential'],
            [
                'level' => BriefTemplate::LEVEL_PREMIUM,
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
                'level' => BriefTemplate::LEVEL_PREMIUM,
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
        $this->deactivateStaleSections($residential->id, array_column($bank, 'key'));

        $commercialBank = $this->commercialBank();
        foreach ($commercialBank as $position => $sectionData) {
            $this->upsertSection($commercial->id, $position, $sectionData);
        }
        $this->deactivateStaleSections($commercial->id, array_column($commercialBank, 'key'));

        $quickBank = $this->quickBank();
        foreach ($quickBank as $position => $sectionData) {
            $this->upsertSection($quick->id, $position, $sectionData);
        }
        $this->deactivateStaleSections($quick->id, array_column($quickBank, 'key'));
    }

    /**
     * Spec Part 8.1, level 1 — «Quick Brief», ~3 min. Every key here also exists
     * in the Premium bank (Part 9), which is what makes the Quick → Premium
     * upgrade lossless: switchTemplate() re-points the answers by key.
     */
    private function quickBank(): array
    {
        $opt = fn (array $pairs) => collect($pairs)
            ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => ['az' => $label]])
            ->values()
            ->all();

        return [
            [
                'key' => 'quick_start',
                'name' => ['az' => 'Layihə haqqında qısa', 'ru' => 'Коротко о проекте', 'en' => 'Project in brief'],
                'icon' => 'bolt',
                'estimated_minutes' => 3,
                'questions' => [
                    ['key' => 'object_type', 'label' => 'Obyektin tipi', 'type' => 'select',
                        'options' => $opt(['apartment' => 'Mənzil', 'house' => 'Fərdi ev']),
                        'required' => true, 'delegatable' => false],
                    ['key' => 'object_address', 'label' => 'Obyektin ünvanı', 'type' => 'text',
                        'options' => null, 'required' => true, 'delegatable' => false],
                    ['key' => 'total_area_sqm', 'label' => 'Ümumi sahə, m²', 'type' => 'number',
                        'options' => null, 'required' => true, 'delegatable' => false],
                    ['key' => 'property_readiness', 'label' => 'Obyektin hazırlığı', 'type' => 'select',
                        'options' => $opt([
                            'new_shell' => 'Təmirsiz yeni tikili', 'resale_repaired' => 'Cari təmirlə ikinci əl',
                            'resale_raw' => 'Təmirsiz ikinci əl', 'other' => 'Digər',
                        ]),
                        'required' => false, 'delegatable' => false],
                    ['key' => 'cooperation_scope', 'label' => 'Əməkdaşlıq formatı', 'type' => 'select',
                        'options' => $opt([
                            'design_only' => 'Yalnız dizayn-layihə',
                            'design_procurement' => 'Dizayn + komplektasiya',
                            'design_supervision' => 'Dizayn + müəllif nəzarəti',
                            'turnkey' => 'Açar təhvili (təmirlə birlikdə)',
                            'rooms_only' => 'Yalnız ayrı otaqlar',
                        ]),
                        'required' => true, 'delegatable' => false],
                    // The AS-IS quick brief already asked for budget outright — the
                    // audit's point was that the detailed one did not (Part 2.2).
                    ['key' => 'project_budget_range', 'label' => 'Layihənin büdcəsi', 'type' => 'budget_range',
                        'options' => null, 'required' => true, 'delegatable' => false,
                        'help' => 'Təxmini diapazon kifayətdir.'],
                    ['key' => 'desired_completion_date', 'label' => 'İstənilən bitmə / köçmə tarixi', 'type' => 'date',
                        'options' => null, 'required' => false, 'delegatable' => false],
                    ['key' => 'style_preferences', 'label' => 'Sizə yaxın olan üslublar', 'type' => 'multiselect',
                        'options' => $opt([
                            'neoclassic' => 'Neoklassika', 'artdeco' => 'Ar-deko', 'eclectic' => 'Eklektika',
                            'minimalism' => 'Minimalizm', 'eco' => 'Eko-üslub', 'japandi' => 'Japandi',
                            'scandi' => 'Skandinav', 'industrial' => 'Sənaye', 'ethnic' => 'Etnik', 'chalet' => 'Şale',
                        ]),
                        'required' => false, 'delegatable' => true],
                    ['key' => 'special_requests', 'label' => 'Ən vacib istəyiniz', 'type' => 'textarea',
                        'options' => null, 'required' => false, 'delegatable' => false],
                    ['key' => 'contact_full_name', 'label' => 'Ad və soyad', 'type' => 'text',
                        'options' => null, 'required' => true, 'delegatable' => false],
                    ['key' => 'contact_phone', 'label' => 'Telefon', 'type' => 'text',
                        'options' => null, 'required' => true, 'delegatable' => false,
                        'help' => 'Ölkə kodu ilə, məsələn +994 50 123 45 67.'],
                    ['key' => 'pdpa_consent', 'label' => 'Şəxsi məlumatlarımın emalına razılıq verirəm', 'type' => 'consent',
                        'options' => null, 'required' => true, 'delegatable' => false,
                        'help' => 'Razılıq olmadan brif göndərilə bilməz.'],
                ],
            ],
        ];
    }

    /**
     * Sections dropped from the bank stay in the table (answers FK to their
     * questions) but leave the wizard — otherwise an old and a new revision of
     * the same brief would be shown side by side.
     *
     * @param  array<int, string>  $keys
     */
    private function deactivateStaleSections(int $templateId, array $keys): void
    {
        BriefSection::where('brief_template_id', $templateId)
            ->whereNotIn('key', $keys)
            ->update(['active' => false]);
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

        $keys = [];

        foreach ($sectionData['questions'] as $qPosition => $q) {
            // Two accepted shapes: the assoc bank format, and the legacy
            // positional [key,label,type,options,required,delegatable,skip?].
            $q = isset($q['key']) ? $q : [
                'key' => $q[0], 'label' => $q[1], 'type' => $q[2], 'options' => $q[3],
                'required' => $q[4], 'delegatable' => $q[5], 'skip' => $q[6] ?? null,
            ];

            $label = $q['label'];
            $help = $q['help'] ?? null;
            $keys[] = $q['key'];

            BriefQuestion::updateOrCreate(
                ['brief_section_id' => $section->id, 'key' => $q['key']],
                [
                    'label' => is_array($label) ? $label : ['az' => $label],
                    'help' => $help === null ? null : (is_array($help) ? $help : ['az' => $help]),
                    'type' => $q['type'],
                    'options' => $q['options'] ?? null,
                    'skip_logic' => $q['skip'] ?? null,
                    'is_required' => (bool) $q['required'],
                    'allows_designer_choice' => (bool) $q['delegatable'],
                    'position' => $qPosition,
                    'active' => true,
                ],
            );
        }

        // Questions removed from the bank keep their answers but leave the form.
        BriefQuestion::where('brief_section_id', $section->id)
            ->whereNotIn('key', $keys)
            ->update(['active' => false]);
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
