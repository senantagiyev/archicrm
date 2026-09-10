<?php

namespace Tests\Feature;

use App\Models\Brief;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\Brief\BriefRiskDetector;
use App\Services\Brief\BriefService;
use Database\Seeders\BriefQuestionBankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «ARCHI CRM — BRİF» spesifikasiyası v1.0 üzrə: bölmə sırası, dinamik otaq
 * tərkibi, matrislər, göndərmə şərtləri (razılıq) və risk detection.
 */
class BriefSpecTest extends TestCase
{
    use RefreshDatabase;

    private function brief(): Brief
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $user = User::create(['name' => 'M', 'email' => 'm@test.az', 'password' => 'secret123', 'role' => 'owner']);
        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);
        $project = Project::create([
            'client_id' => $client->id, 'name' => 'L', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);

        return app(BriefService::class)->forProject($project);
    }

    private function answer(Brief $brief, string $key, mixed $value): void
    {
        $question = BriefQuestion::where('key', $key)
            ->whereIn('brief_section_id', BriefSection::where('brief_template_id', $brief->brief_template_id)->pluck('id'))
            ->firstOrFail();

        $brief->answers()->updateOrCreate(
            ['brief_question_id' => $question->id, 'brief_room_id' => null],
            ['value' => $value, 'delegated_to_designer' => false, 'answered_at' => now()],
        );
    }

    public function test_section_order_matches_spec_part_8_2(): void
    {
        $brief = $this->brief();

        $general = BriefSection::where('brief_template_id', $brief->brief_template_id)
            ->where('active', true)
            ->whereNull('room_type')
            ->orderBy('position')
            ->pluck('key')
            ->all();

        $this->assertSame([
            'about_you', 'object', 'format_budget', 'aesthetics', 'finish_materials',
            'lighting', 'engineering', 'rooms_hub', 'procurement', 'contacts',
        ], $general);
    }

    public function test_room_inventory_creates_and_numbers_room_instances(): void
    {
        $brief = $this->brief();

        app(BriefService::class)->syncRooms($brief, ['kitchen' => 1, 'kids' => 2]);

        $rooms = $brief->fresh()->rooms;

        $this->assertCount(3, $rooms);
        $this->assertSame(2, $rooms->where('room_type', 'kids')->count());
        $this->assertContains('Uşaq otağı 2', $rooms->pluck('label')->all());

        // Only the selected rooms get an accordion (spec Part 10 №18).
        $keys = app(BriefService::class)->sectionMap($brief->fresh())->map(fn ($e) => $e['section']->key)->all();
        $this->assertContains('room_kitchen', $keys);
        $this->assertNotContains('room_bathroom', $keys);
    }

    public function test_unchecking_a_room_keeps_instances_that_already_have_answers(): void
    {
        $brief = $this->brief();
        $service = app(BriefService::class);

        $service->syncRooms($brief, ['kitchen' => 1]);
        $room = $brief->fresh()->rooms->first();

        $question = BriefQuestion::whereHas('section', fn ($q) => $q->where('key', 'room_kitchen'))->firstOrFail();
        $brief->answers()->create([
            'brief_question_id' => $question->id, 'brief_room_id' => $room->id,
            'value' => 'daily', 'answered_at' => now(),
        ]);

        $service->syncRooms($brief->fresh(), []);

        $this->assertCount(1, $brief->fresh()->rooms);
    }

    public function test_conditional_logic_crosses_section_boundaries(): void
    {
        $brief = $this->brief();

        // §7 «Mühəndislik» balcony question depends on §8 room_inventory.
        $balconyQuestion = BriefQuestion::where('key', 'balcony_insulate')->firstOrFail();

        $this->assertFalse($balconyQuestion->shouldShow(app(BriefService::class)->valuesByKey($brief)));

        $this->answer($brief, 'room_inventory', ['balcony' => 1]);

        $this->assertTrue($balconyQuestion->shouldShow(app(BriefService::class)->valuesByKey($brief->fresh())));
    }

    public function test_matrix_row_condition_and_display_value(): void
    {
        $brief = $this->brief();
        $question = BriefQuestion::where('key', 'contractor_matrix')->firstOrFail();
        $other = BriefQuestion::where('key', 'contractor_other_category')->firstOrFail();

        $this->assertFalse($other->shouldShow(['contractor_matrix' => ['crew' => 'own']]));
        $this->assertTrue($other->shouldShow(['contractor_matrix' => ['other' => 'own']]));

        $this->assertSame(
            'Təmir briqadası: Öz podratçısı var',
            $question->displayValue(['crew' => 'own']),
        );
    }

    public function test_brief_cannot_be_sent_without_consent_and_required_answers(): void
    {
        $brief = $this->brief();
        $service = app(BriefService::class);

        $this->assertTrue($service->missingRequired($brief)->isNotEmpty());

        foreach ([
            'object_address' => 'Bakı, Nizami 1',
            'object_type' => 'apartment',
            'total_area_sqm' => '120',
            'design_area_sqm' => '120',
            'property_readiness' => 'new_shell',
            'utilities_available' => ['water'],
            'premises_purpose' => 'permanent',
            'cooperation_scope' => 'design_only',
            'project_budget_range' => ['min' => '50000', 'max' => '80000', 'currency' => 'AZN'],
            'room_inventory' => ['kitchen' => 1],
            'contact_full_name' => 'Aygün Əliyeva',
            'contact_phone' => '+994501234567',
        ] as $key => $value) {
            $this->answer($brief, $key, $value);
        }

        $service->syncRooms($brief->fresh(), ['kitchen' => 1]);

        // Only the consent checkbox is left (spec Part 10 №20).
        $missing = $service->missingRequired($brief->fresh());
        $this->assertSame(['pdpa_consent'], $missing->map(fn ($m) => $m['question']->key)->all());

        $this->answer($brief->fresh(), 'pdpa_consent', '1');

        $this->assertTrue($service->missingRequired($brief->fresh())->isEmpty());
    }

    public function test_quick_brief_exists_beside_premium_and_is_never_the_default(): void
    {
        $brief = $this->brief();

        $quick = BriefTemplate::where('key', 'quick')->firstOrFail();

        $this->assertTrue($quick->isQuick());
        $this->assertNotSame($quick->id, BriefTemplate::default()->id);
        $this->assertNotSame($quick->id, $brief->brief_template_id);

        // Spec Part 8.1: ~3 min, one short section.
        $sections = BriefSection::where('brief_template_id', $quick->id)->where('active', true)->get();
        $this->assertCount(1, $sections);
        $this->assertSame(3, $sections->first()->estimated_minutes);
    }

    public function test_switching_quick_to_premium_carries_the_answers_over(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $user = User::create(['name' => 'M', 'email' => 'q@test.az', 'password' => 'secret123', 'role' => 'owner']);
        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);
        $project = Project::create([
            'client_id' => $client->id, 'name' => 'Q', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);

        $service = app(BriefService::class);
        $quick = BriefTemplate::where('key', 'quick')->firstOrFail();
        $premium = BriefTemplate::where('key', 'residential')->firstOrFail();

        $brief = $service->forProject($project);
        $service->switchTemplate($brief, $quick);

        foreach ([
            'object_address' => 'Bakı, Nizami 1',
            'total_area_sqm' => '90',
            'project_budget_range' => ['min' => '40000', 'max' => '60000', 'currency' => 'AZN'],
        ] as $key => $value) {
            $this->answer($brief->fresh(), $key, $value);
        }

        $service->switchTemplate($brief->fresh(), $premium);

        $values = $service->valuesByKey($brief->fresh());

        $this->assertSame('Bakı, Nizami 1', $values['object_address']);
        $this->assertSame('90', $values['total_area_sqm']);
        $this->assertSame('60000', $values['project_budget_range']['max']);

        // The answers now belong to the premium template's own questions.
        $premiumQuestionIds = BriefQuestion::whereIn(
            'brief_section_id',
            BriefSection::where('brief_template_id', $premium->id)->pluck('id')
        )->pluck('id');

        $this->assertTrue(
            $brief->fresh()->answers()->whereIn('brief_question_id', $premiumQuestionIds)->count() >= 3,
        );
    }

    public function test_general_section_rows_cannot_be_duplicated(): void
    {
        $brief = $this->brief();
        $section = BriefSection::where('key', 'object')->firstOrFail();

        // brief_room_id is NULL for general sections; before room_key existed the
        // unique index did not hold and parallel autosaves duplicated the row.
        foreach (range(1, 3) as $ignored) {
            $brief->sectionStates()->firstOrCreate(
                ['brief_section_id' => $section->id, 'brief_room_id' => null],
                ['status' => 'in_progress'],
            );
        }

        $this->assertSame(1, $brief->sectionStates()->where('brief_section_id', $section->id)->count());
    }

    public function test_risk_detection_flags_budget_measurement_and_curtain_conflicts(): void
    {
        $brief = $this->brief();

        $this->answer($brief, 'design_area_sqm', '200');
        $this->answer($brief, 'project_budget_range', ['min' => '10000', 'max' => '20000', 'currency' => 'AZN']);
        $this->answer($brief, 'has_measurement_plan', 'no');
        $this->answer($brief, 'curtains_type', 'none');
        $this->answer($brief, 'curtains_blackout_location', 'Yataq otağı');
        $this->answer($brief, 'wall_materials', ['paint', 'designer']);

        $codes = collect(app(BriefRiskDetector::class)->detect($brief->fresh()))->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['R1', 'R4', 'R5', 'R6'], $codes);
    }
}
