<?php

namespace Database\Seeders;

use App\Models\BriefQuestion;
use App\Models\BriefSection;
use Illuminate\Database\Seeder;

class BriefQuestionBankSeeder extends Seeder
{
    /**
     * Idempotent upsert by key — the bank lives in database/seeders/brief/bank.php
     * (versioned in git), runtime rows are editable in Filament without breaking
     * old answers (answers FK to question ids; keys are stable).
     */
    public function run(): void
    {
        $bank = require database_path('seeders/brief/bank.php');

        foreach ($bank as $position => $sectionData) {
            $section = BriefSection::updateOrCreate(
                ['key' => $sectionData['key']],
                [
                    'name' => $sectionData['name'],
                    'icon' => $sectionData['icon'] ?? null,
                    'room_type' => $sectionData['room_type'] ?? null,
                    'position' => $position,
                    'active' => true,
                ],
            );

            foreach ($sectionData['questions'] as $qPosition => [$key, $label, $type, $options, $required, $delegatable]) {
                BriefQuestion::updateOrCreate(
                    ['brief_section_id' => $section->id, 'key' => $key],
                    [
                        'label' => is_array($label) ? $label : ['az' => $label],
                        'type' => $type,
                        'options' => $options,
                        'is_required' => $required,
                        'allows_designer_choice' => $delegatable,
                        'position' => $qPosition,
                        'active' => true,
                    ],
                );
            }
        }
    }
}
