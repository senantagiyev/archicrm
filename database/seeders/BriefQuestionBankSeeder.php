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
            $questionCount = count($sectionData['questions']);

            $section = BriefSection::updateOrCreate(
                ['key' => $sectionData['key']],
                [
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
    }
}
