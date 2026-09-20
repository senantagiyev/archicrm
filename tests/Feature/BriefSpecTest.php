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
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brif quruluşu: bölmə sırası (Roomix «Премиум бриф» ilə bir-bir — bax
 * docs/roomix-brief-parity.md), dinamik otaq tərkibi, matrislər, yeni
 * vidjetlər (repeater · std_or_custom · image_rating), göndərmə şərtləri
 * (razılıq) və risk detection.
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

    public function test_section_order_matches_roomix(): void
    {
        $brief = $this->brief();

        $general = BriefSection::where('brief_template_id', $brief->brief_template_id)
            ->where('active', true)
            ->whereNull('room_type')
            ->orderBy('position')
            ->pluck('key')
            ->all();

        // Roomix: О вас · Объект · Комплектация · Эстетика · Отделочные материалы ·
        // Освещение · Помещения · Инженерия · Контакты.
        $this->assertSame([
            'about_you', 'object', 'procurement', 'aesthetics', 'finish_materials',
            'lighting', 'rooms_hub', 'engineering', 'contacts',
        ], $general);
    }

    public function test_room_inventory_creates_and_numbers_room_instances(): void
    {
        $brief = $this->brief();

        app(BriefService::class)->syncRooms($brief, ['kitchen_furniture' => 1, 'kids' => 2]);

        $rooms = $brief->fresh()->rooms;

        $this->assertCount(3, $rooms);
        $this->assertSame(2, $rooms->where('room_type', 'kids')->count());
        $this->assertContains('Uşaq yataq otağı 2', $rooms->pluck('label')->all());

        // Only the selected rooms get an accordion (spec Part 10 №18).
        $keys = app(BriefService::class)->sectionMap($brief->fresh())->map(fn ($e) => $e['section']->key)->all();
        $this->assertContains('room_kitchen_furniture', $keys);
        $this->assertNotContains('room_bathroom', $keys);
    }

    public function test_unchecking_a_room_keeps_instances_that_already_have_answers(): void
    {
        $brief = $this->brief();
        $service = app(BriefService::class);

        $service->syncRooms($brief, ['kitchen_furniture' => 1]);
        $room = $brief->fresh()->rooms->first();

        $question = BriefQuestion::whereHas('section', fn ($q) => $q->where('key', 'room_kitchen_furniture'))->firstOrFail();
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

        // §8 «Mühəndislik» balkon sualı §7 room_inventory-dən asılıdır.
        $balconyQuestion = BriefQuestion::where('key', 'balcony_works')->firstOrFail();

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
            'Təmir briqadası: Öz podratçım var',
            $question->displayValue(['crew' => 'own']),
        );
    }

    /**
     * Roomix-dən gətirilən üç yeni vidjet. Hər biri cavabı fərqli formada
     * saxlayır, ona görə displayValue() hər üçünü ayrıca tanımalıdır — əks
     * halda xülasə və PDF ixracı «Array to string conversion» verir.
     */
    public function test_new_roomix_widgets_render_their_answers(): void
    {
        $this->brief();

        // Ailə tərkibi — sətir-sətir cədvəl.
        $members = BriefQuestion::where('key', 'household_members')->firstOrFail();
        $this->assertSame('repeater', $members->type);
        $this->assertSame(
            "Ata · Elçin · 41\nQızı · Nərgiz · 9",
            $members->displayValue([
                ['role' => 'Ata', 'name' => 'Elçin', 'age' => '41', 'height' => '', 'handedness' => ''],
                ['role' => 'Qızı', 'name' => 'Nərgiz', 'age' => '9'],
            ]),
        );

        // Mebel hündürlükləri — standart ölçü və ya öz ölçün. «Standart üzrə»
        // etiketi tərcümələrdən gəlir, ona görə onları da yükləyirik.
        $this->seed(TranslationSeeder::class);

        $heights = BriefQuestion::where('key', 'furniture_heights')->firstOrFail();
        $this->assertSame('std_or_custom', $heights->type);
        $this->assertSame(
            'Mətbəx iş səthi: 950 mm · Tropik duş: 2100 mm (Standart üzrə)',
            $heights->displayValue([
                'kitchen_worktop' => ['mode' => 'custom', 'value' => '950'],
                'rain_shower' => ['mode' => 'std'],
            ]),
        );

        // Rəng kombinasiyaları — 27 kart, hər biri bəyənilir və ya bəyənilmir.
        $combos = BriefQuestion::where('key', 'color_combinations')->firstOrFail();
        $this->assertSame('image_rating', $combos->type);
        $this->assertCount(27, $combos->options);
        $this->assertSame('Kombinasiya 1 ♥ · Kombinasiya 3 ✕', $combos->displayValue([
            'combo_1' => 'like',
            'combo_2' => null,
            'combo_3' => 'dislike',
        ]));
    }

    /**
     * Bankdakı rəng dəyərləri Roomix-dən ölçülüb — kartlar foto olmadan da
     * doğru görünməlidir, ona görə hər palitra və hər metal çipi yerindədir.
     */
    public function test_colour_driven_options_carry_their_swatches(): void
    {
        $this->brief();

        $combos = BriefQuestion::where('key', 'color_combinations')->firstOrFail();
        foreach ($combos->options as $option) {
            $this->assertCount(5, $option['colors']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $option['colors'][0]);
        }

        $metals = BriefQuestion::where('key', 'preferred_metals')->firstOrFail();
        $gold = collect($metals->options)->firstWhere('value', 'gold');
        $this->assertSame(['#d4af37'], $gold['colors']);

        // «Dizaynerin ixtiyarına» rəng deyil — çipsiz qalır.
        $designer = collect($metals->options)->firstWhere('value', 'designer');
        $this->assertSame([], $designer['colors']);
    }

    /**
     * `std_or_custom` variantları `options.items` altındadır — siyahı deyil,
     * konfiqdir. Seeder onları da qorumalıdır, əks halda hər deploy mebel
     * hündürlüklərinin nümunə şəkillərini silərdi.
     */
    public function test_reseeding_keeps_images_of_itemised_rows(): void
    {
        $this->brief();

        $heights = BriefQuestion::where('key', 'furniture_heights')->firstOrFail();
        $options = $heights->options;
        $options['items'][0]['images'] = ['brief/inspiration/worktop.webp'];
        $heights->update(['options' => $options]);

        $this->seed(BriefQuestionBankSeeder::class);

        $this->assertSame(
            ['brief/inspiration/worktop.webp'],
            BriefQuestion::where('key', 'furniture_heights')->firstOrFail()->options['items'][0]['images'],
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
            'object_utilities' => ['water'],
            'premises_purpose' => 'residential',
            'cooperation_scope' => 'design_only',
            'project_budget_range' => ['min' => '50000', 'max' => '80000', 'currency' => 'AZN'],
            'room_inventory' => ['kitchen_furniture' => 1],
            'contact_full_name' => 'Aygün Əliyeva',
            'contact_phone' => '+994501234567',
            'contact_email' => 'aygun@test.az',
        ] as $key => $value) {
            $this->answer($brief, $key, $value);
        }

        $service->syncRooms($brief->fresh(), ['kitchen_furniture' => 1]);

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
        $this->answer($brief, 'curtains', ['none']);
        $this->answer($brief, 'blackout_zones', 'Yataq otağı');
        $this->answer($brief, 'wall_materials', ['paint', 'designer']);

        $codes = collect(app(BriefRiskDetector::class)->detect($brief->fresh()))->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['R1', 'R4', 'R5', 'R6'], $codes);
    }
}
