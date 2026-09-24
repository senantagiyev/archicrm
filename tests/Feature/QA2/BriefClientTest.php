<?php

namespace Tests\Feature\QA2;

use App\Models\Brief;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Services\Brief\BriefService;
use App\Support\TenantContext;
use Database\Seeders\BriefQuestionBankSeeder;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA2 — brifin MÜŞTƏRİ tərəfi (passwordless portal, `auth:customer`).
 *
 * Bu fayl məhsulun ən kritik axınını real HTTP sorğuları ilə yoxlayır: hər sual
 * tipinin uçdan-uca dövrəsi (POST → baza → səhifədə geri görünmə), hədlər,
 * IDOR, faiz arifmetikası, saxla/davam et və markup-un həqiqətən kliklənə bilən
 * qalması.
 *
 * Niyə ayrı fayl: `tests/Feature/QA/BriefClientQaTest.php` axının ümumi
 * mənzərəsini tutur; burada isə HƏR tip ayrıca, HƏR hədd ayrıca və render
 * nəticəsi ayrıca yoxlanılır — birini pozan dəyişiklik dəqiq hansı tipdə
 * sındığını göstərsin.
 */
class BriefClientTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private Brief $brief;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');

        $this->seed(BriefQuestionBankSeeder::class);
        $this->seed(TranslationSeeder::class);

        // Müştəri portalı az dilindədir — tərcümə açarlarının sızması məhz bu
        // dildə yoxlanılmalıdır.
        App::setLocale('az');

        $this->studio = StudioWorld::make('brief-qa2');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $this->brief = app(BriefService::class)->forProject($this->studio->project);
        });
    }

    // ───────────────────────── köməkçilər ─────────────────────────

    private function service(): BriefService
    {
        return app(BriefService::class);
    }

    private function customer()
    {
        return $this->actingAs($this->studio->portalUser, 'customer');
    }

    private function section(string $key): BriefSection
    {
        return BriefSection::where('brief_template_id', $this->brief->brief_template_id)
            ->where('key', $key)
            ->firstOrFail();
    }

    /** Bankda açarlar şablonlar arasında təkrarlanır — YALNIZ bu brifin şablonu. */
    private function question(string $key): BriefQuestion
    {
        return BriefQuestion::whereIn(
            'brief_section_id',
            BriefSection::where('brief_template_id', $this->brief->brief_template_id)->select('id')
        )->where('key', $key)->firstOrFail();
    }

    private function autosave(BriefQuestion $q, mixed $value, bool $delegated = false, ?int $roomId = null)
    {
        return $this->customer()->patchJson(
            route('portal.brief.autosave', [$this->studio->project->id, $q->brief_section_id]),
            ['question_id' => $q->id, 'value' => $value, 'delegated' => $delegated, 'room_id' => $roomId],
        );
    }

    private function save(string $key, mixed $value, ?int $roomId = null)
    {
        return $this->autosave($this->question($key), $value, false, $roomId);
    }

    private function storedValue(string $key, ?int $roomId = null): mixed
    {
        return $this->brief->answers()
            ->where('brief_question_id', $this->question($key)->id)
            ->where('brief_room_id', $roomId)
            ->first()?->value;
    }

    /** Bölmə səhifəsinin HTML-i (render geri-oxuma testləri üçün). */
    private function html(string $sectionKey, ?int $roomId = null): string
    {
        $response = $this->customer()->get(route(
            'portal.brief.section',
            array_filter([$this->studio->project->id, $this->section($sectionKey)->id, $roomId]),
        ));

        $response->assertOk();

        return $response->getContent();
    }

    /** Sualın kartı — `data-question="ID"`-dan növbəti kartın başlanğıcına qədər. */
    private function block(string $html, BriefQuestion $question): string
    {
        $needle = 'data-question="'.$question->id.'"';
        $start = strpos($html, $needle);
        $this->assertNotFalse($start, 'Sualın kartı səhifədə yoxdur: '.$question->key);

        $next = strpos($html, 'data-question="', $start + strlen($needle));

        return $next === false
            ? substr($html, $start)
            : substr($html, $start, $next - $start);
    }

    // ═════════════ 1. Hər sual tipi: POST → baza → səhifədə geri ═════════════

    public function test_text_number_and_date_round_trip_and_render_back(): void
    {
        $this->save('object_address', 'Bakı, Nizami küç. 1')->assertOk()->assertJson(['ok' => true]);
        $this->save('total_area_sqm', '138.5')->assertOk();
        $this->save('desired_start_date', '2026-03-01')->assertOk();

        $this->assertSame('Bakı, Nizami küç. 1', $this->storedValue('object_address'));
        $this->assertSame('138.5', $this->storedValue('total_area_sqm'));
        $this->assertSame('2026-03-01', $this->storedValue('desired_start_date'));

        $object = $this->html('object');
        $this->assertStringContainsString('value="Bakı, Nizami küç. 1"', $object);
        $this->assertStringContainsString('value="138.5"', $object);

        $this->assertStringContainsString('value="2026-03-01"', $this->html('procurement'));
    }

    public function test_textarea_round_trips_and_renders_inside_the_textarea(): void
    {
        $text = "Birinci sətir\nİkinci sətir";

        $this->save('special_requests', $text)->assertOk();
        $this->assertSame($text, $this->storedValue('special_requests'));

        $block = $this->block($this->html('contacts'), $this->question('special_requests'));
        $this->assertStringContainsString('<textarea', $block);
        $this->assertStringContainsString('Birinci sətir', $block);
        $this->assertStringContainsString('İkinci sətir', $block);
    }

    public function test_select_and_multiselect_render_the_picked_options_as_selected(): void
    {
        $this->save('object_type', 'house')->assertOk();
        $this->save('lead_source', ['instagram', 'friends'])->assertOk();

        $this->assertSame('house', $this->storedValue('object_type'));
        $this->assertSame(['instagram', 'friends'], $this->storedValue('lead_source'));

        // `bg-accent-dark` sadəcə görüntü deyil — autosave skripti seçilmiş
        // variantı MƏHZ bu klasla oxuyur (`collect()`), ona görə render geri
        // oxunanda o klas düzgün variantın üstündə olmalıdır.
        $select = $this->block($this->html('object'), $this->question('object_type'));
        $this->assertMatchesRegularExpression(
            '/value="house"[^>]*(?:\n[^<]*)*?bg-accent-dark/s',
            $select,
            'Seçilmiş `select` variantı `bg-accent-dark` ilə işarələnməlidir.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/value="apartment"[^>]*(?:\n[^<]*)*?bg-accent-dark/s',
            $select,
            'Seçilməyən variant işarəli qalmamalıdır.',
        );

        $multi = $this->block($this->html('about_you'), $this->question('lead_source'));
        $this->assertStringContainsString('data-multi-choice', $multi);
        foreach (['instagram', 'friends'] as $on) {
            $this->assertMatchesRegularExpression(
                '/value="'.$on.'"[^>]*(?:\n[^<]*)*?bg-accent-dark/s',
                $multi,
                "Seçilmiş variant işarələnməlidir: $on",
            );
        }
        $this->assertDoesNotMatchRegularExpression(
            '/value="vk"[^>]*(?:\n[^<]*)*?bg-accent-dark/s',
            $multi,
        );
    }

    public function test_boolean_and_consent_round_trip_and_render_back(): void
    {
        $this->save('has_pets', '1')->assertOk();
        $this->save('pdpa_consent', '1')->assertOk();

        $this->assertSame('1', $this->storedValue('has_pets'));
        $this->assertSame('1', $this->storedValue('pdpa_consent'));

        $boolean = $this->block($this->html('about_you'), $this->question('has_pets'));
        $this->assertMatchesRegularExpression('/value="1"[^>]*(?:\n[^<]*)*?bg-accent-dark/s', $boolean);

        $consent = $this->block($this->html('contacts'), $this->question('pdpa_consent'));
        $this->assertStringContainsString('data-consent', $consent);
        $this->assertStringContainsString('checked', $consent);

        // «Yox» da eyni yolla saxlanmalıdır — `0` boş dəyər deyil.
        $this->save('has_pets', '0')->assertOk();
        $this->assertSame('0', $this->storedValue('has_pets'));
        $this->assertMatchesRegularExpression(
            '/value="0"[^>]*(?:\n[^<]*)*?bg-accent-dark/s',
            $this->block($this->html('about_you'), $this->question('has_pets')),
        );
    }

    /**
     * Şəkil kartı seçimi: variantın şəkli admin tərəfindən `public` diskə
     * yüklənir, səhifədə isə `/storage/...` linki kimi görünməlidir. Link
     * `storage/app/private`-ə düşsəydi kartlar boş qalardı (şəkil 404).
     */
    public function test_image_multiselect_round_trips_and_renders_the_uploaded_option_image(): void
    {
        $question = $this->question('style_preferences');
        $options = $question->options;
        $options[0]['image_url'] = 'brief/options/neoclassic.webp';
        $question->update(['options' => $options]);

        Storage::disk('public')->put('brief/options/neoclassic.webp', 'fake-webp-bytes');

        $this->autosave($question, ['neoclassic', 'japandi'])->assertOk();
        $this->assertSame(['neoclassic', 'japandi'], $this->brief->answers()
            ->where('brief_question_id', $question->id)->first()->value);

        $block = $this->block($this->html('aesthetics'), $question);

        // Seçim nişanı şəkil kartlarında `data-selected` atributudur — `collect()`
        // onu oxuyur, ona görə render geri-oxunanda mütləq orada olmalıdır.
        $this->assertMatchesRegularExpression('/value="neoclassic"[^>]*(?:\s|\n)*data-selected/s', $block);
        $this->assertMatchesRegularExpression('/value="japandi"[^>]*(?:\s|\n)*data-selected/s', $block);
        $this->assertStringNotContainsString('value="loft" data-selected', $block);

        $this->assertStringContainsString('/storage/brief/options/neoclassic.webp', $block);
        $this->assertStringNotContainsString('storage/app/private', $block);
        $this->assertTrue(
            Storage::disk('public')->exists('brief/options/neoclassic.webp'),
            'Səhifədəki şəkil linki `public` diskdə real mövcud fayla getməlidir.',
        );
    }

    /** `image_select` bankda yoxdur, amma blade onu dəstəkləyir — tipi ayrıca yoxlayırıq. */
    public function test_image_select_round_trips_and_keeps_exactly_one_card_selected(): void
    {
        $question = $this->makeQuestion('qa_image_select', 'image_select', [
            ['value' => 'card_a', 'label' => ['az' => 'Kart A'], 'image_url' => 'brief/options/a.webp'],
            ['value' => 'card_b', 'label' => ['az' => 'Kart B'], 'image_url' => null],
        ]);

        Storage::disk('public')->put('brief/options/a.webp', 'fake');

        $this->autosave($question, 'card_b')->assertOk();
        $this->assertSame('card_b', $this->brief->answers()
            ->where('brief_question_id', $question->id)->first()->value);

        $block = $this->block($this->html('aesthetics'), $question);
        $this->assertStringContainsString('data-single-choice', $block);
        $this->assertMatchesRegularExpression('/value="card_b"[^>]*(?:\s|\n)*data-selected/s', $block);
        $this->assertDoesNotMatchRegularExpression('/value="card_a"[^>]*(?:\s|\n)*data-selected/s', $block);

        // Siyahıda olmayan kart qəbul edilməməlidir.
        $this->autosave($question, 'card_z')->assertStatus(422);
        $this->assertSame('card_b', $this->brief->answers()
            ->where('brief_question_id', $question->id)->first()->value);
    }

    public function test_image_rating_stores_per_card_verdicts_and_renders_them_back(): void
    {
        $question = $this->question('color_combinations');

        $this->autosave($question, ['combo_1' => 'like', 'combo_3' => 'dislike'])->assertOk();

        $stored = $this->brief->answers()->where('brief_question_id', $question->id)->first()->value;
        $this->assertSame(['combo_1' => 'like', 'combo_3' => 'dislike'], $stored);
        $this->assertArrayNotHasKey('combo_2', $stored, 'Rəy verilməyən kart cavabda olmamalıdır.');

        $block = $this->block($this->html('aesthetics'), $question);

        // Kartın çərçivəsi rəyi göstərir, düymənin `text-white` klası isə
        // `collect()`-in oxuduğu nişandır.
        $like = $this->slice($block, 'data-rating-row="combo_1"', 'data-rating-row="combo_2"');
        $this->assertStringContainsString('border-ok', $like);
        $this->assertMatchesRegularExpression('/value="like"[^>]*(?:\n[^<]*)*?text-white/s', $like);

        $dislike = $this->slice($block, 'data-rating-row="combo_3"', 'data-rating-row="combo_4"');
        $this->assertStringContainsString('border-danger', $dislike);
        $this->assertMatchesRegularExpression('/value="dislike"[^>]*(?:\n[^<]*)*?text-white/s', $dislike);

        $neutral = $this->slice($block, 'data-rating-row="combo_2"', 'data-rating-row="combo_3"');
        $this->assertStringNotContainsString('text-white', $neutral);
    }

    public function test_std_or_custom_stores_standard_and_own_size_and_renders_both_back(): void
    {
        $question = $this->question('furniture_heights');
        $items = $question->options['items'];
        [$first, $second] = [$items[0]['value'], $items[1]['value']];

        $this->autosave($question, [
            $first => ['mode' => 'std'],
            $second => ['mode' => 'custom', 'value' => '870'],
        ])->assertOk();

        $this->assertSame([
            $first => ['mode' => 'std'],
            $second => ['mode' => 'custom', 'value' => '870'],
        ], $this->brief->answers()->where('brief_question_id', $question->id)->first()->value);

        $block = $this->block($this->html('object'), $question);

        $stdRow = $this->slice($block, 'data-stdcustom-row="'.$first.'"', 'data-stdcustom-row="'.$second.'"');
        $this->assertMatchesRegularExpression('/value="std"[^>]*(?:\n[^<]*)*?bg-accent-dark/s', $stdRow);
        // Standart rejimdə öz ölçüsü sahəsi gizli qalır.
        $this->assertMatchesRegularExpression('/data-stdcustom-value[^>]*hidden/s', $stdRow);

        $customRow = $this->slice($block, 'data-stdcustom-row="'.$second.'"', 'data-stdcustom-row="'.($items[2]['value'] ?? 'yox').'"');
        $this->assertMatchesRegularExpression('/value="custom"[^>]*(?:\n[^<]*)*?bg-accent-dark/s', $customRow);
        $this->assertStringContainsString('value="870"', $customRow);
    }

    public function test_repeater_rows_keep_their_order_through_add_and_remove(): void
    {
        $question = $this->question('household_members');

        $rows = [
            ['role' => 'Ata', 'name' => 'Elçin', 'age' => '44', 'height' => '1820', 'handedness' => 'right'],
            ['role' => 'Ana', 'name' => 'Nigar', 'age' => '41', 'height' => '1650', 'handedness' => 'left'],
            ['role' => 'Oğul', 'name' => 'Tural', 'age' => '12', 'height' => '1400', 'handedness' => 'right'],
        ];

        $this->autosave($question, $rows)->assertOk();
        $this->assertSame($rows, $this->brief->answers()->where('brief_question_id', $question->id)->first()->value);

        // Səhifə sətirləri EYNİ sıra ilə göstərməlidir.
        $block = $this->block($this->html('about_you'), $question);
        $positions = array_map(fn ($name) => strpos($block, 'value="'.$name.'"'), ['Elçin', 'Nigar', 'Tural']);
        $this->assertNotContains(false, $positions, 'Hər sətir render olunmalıdır.');
        $this->assertSame($positions, array_values(array_filter($positions)), 'Sıra dəyişməməlidir.');
        $this->assertTrue($positions[0] < $positions[1] && $positions[1] < $positions[2], 'Sətirlərin sırası pozulub.');

        // Ortadan bir sətir silinir (JS sətri DOM-dan çıxarıb bütün dəsti yenidən yazır).
        $afterRemoval = [$rows[0], $rows[2]];
        $this->autosave($question, $afterRemoval)->assertOk();
        $this->assertSame($afterRemoval, $this->brief->answers()->where('brief_question_id', $question->id)->first()->value);
        $this->assertSame(
            1,
            $this->brief->answers()->where('brief_question_id', $question->id)->count(),
            'Təkrar yazı yeni sətir yaratmamalıdır.',
        );

        $block = $this->block($this->html('about_you'), $question);
        $this->assertStringNotContainsString('value="Nigar"', $block);
        // 2 real sətir + 1 `<template>` sətri (yeni sətir onun klonudur).
        $this->assertSame(3, substr_count($block, 'data-rep-row class'), 'İki sətir və bir şablon gözlənilir.');
    }

    public function test_matrix_stores_one_column_per_row_and_renders_the_picked_cells(): void
    {
        $question = $this->question('contractor_matrix');

        $this->autosave($question, ['crew' => 'own', 'lighting' => 'need_recommendation'])->assertOk();
        $this->assertSame(
            ['crew' => 'own', 'lighting' => 'need_recommendation'],
            $this->brief->answers()->where('brief_question_id', $question->id)->first()->value,
        );

        $block = $this->block($this->html('procurement'), $question);

        $crew = $this->slice($block, 'data-matrix-row="crew"', 'data-matrix-row="appliances"');
        $this->assertMatchesRegularExpression('/value="own"[^>]*(?:\n[^<]*)*?bg-accent-dark/s', $crew);
        $this->assertDoesNotMatchRegularExpression('/value="none"[^>]*(?:\n[^<]*)*?bg-accent-dark/s', $crew);

        $appliances = $this->slice($block, 'data-matrix-row="appliances"', 'data-matrix-row="plumbing"');
        $this->assertStringNotContainsString('bg-accent-dark', $appliances, 'Cavabsız sətirdə seçim olmamalıdır.');
    }

    public function test_budget_range_round_trips_and_renders_the_amounts(): void
    {
        $question = $this->question('project_budget_range');

        $this->autosave($question, ['min' => '40000', 'max' => '90000', 'currency' => 'USD'])->assertOk();
        $this->assertSame(
            ['min' => '40000', 'max' => '90000', 'currency' => 'USD'],
            $this->brief->answers()->where('brief_question_id', $question->id)->first()->value,
        );

        $block = $this->block($this->html('procurement'), $question);
        $this->assertStringContainsString('value="40000"', $block);
        $this->assertStringContainsString('value="90000"', $block);
        $this->assertMatchesRegularExpression('/value="USD"\s+selected/s', $block);
    }

    public function test_color_swatch_stores_base_and_accent_and_renders_the_roles(): void
    {
        $question = $this->makeQuestion('qa_color_swatch', 'color_swatch', [
            'swatches' => ['#ffffff', '#111111', '#c0a080'],
            'base_max' => 2,
            'accent_max' => 1,
        ]);

        $this->autosave($question, ['base' => ['#ffffff'], 'accent' => ['#111111']])->assertOk();
        $this->assertSame(
            ['base' => ['#ffffff'], 'accent' => ['#111111']],
            $this->brief->answers()->where('brief_question_id', $question->id)->first()->value,
        );

        $block = $this->block($this->html('aesthetics'), $question);
        $this->assertMatchesRegularExpression('/value="#ffffff"[^>]*(?:\n[^>]*)*?data-role="base"/s', $block);
        $this->assertMatchesRegularExpression('/value="#111111"[^>]*(?:\n[^>]*)*?data-role="accent"/s', $block);
        $this->assertMatchesRegularExpression('/value="#c0a080"[^>]*(?:\n[^>]*)*?data-role=""/s', $block);
    }

    public function test_room_inventory_creates_rooms_and_renders_the_counters(): void
    {
        $question = $this->question('room_inventory');

        $this->autosave($question, ['bedroom' => 2, 'kids' => 1])->assertOk();

        $this->assertSame(['bedroom' => 2, 'kids' => 1], $this->brief->answers()
            ->where('brief_question_id', $question->id)->first()->value);
        $this->assertSame(2, $this->brief->fresh()->rooms()->where('room_type', 'bedroom')->count());
        $this->assertSame(1, $this->brief->fresh()->rooms()->where('room_type', 'kids')->count());

        $block = $this->block($this->html('rooms_hub'), $question);
        $bedroom = $this->slice($block, 'data-inventory-row="bedroom"', 'data-inventory-row="wardrobe_master"');
        $this->assertMatchesRegularExpression('/data-inv-count[^>]*>\s*2\s*</s', $bedroom);

        $hall = $this->slice($block, 'data-inventory-row="hallway"', 'data-inventory-row="kitchen_furniture"');
        $this->assertMatchesRegularExpression('/data-inv-count[^>]*>\s*0\s*</s', $hall);

        // Otaq cavabları bir-birindən ayrı saxlanılır.
        $rooms = $this->brief->fresh()->rooms()->where('room_type', 'bedroom')->get();
        $roomQuestion = $this->question('bedroom_notes');
        $this->autosave($roomQuestion, 'Birinci yataq', false, $rooms[0]->id)->assertOk();
        $this->autosave($roomQuestion, 'İkinci yataq', false, $rooms[1]->id)->assertOk();

        $this->assertSame('Birinci yataq', $this->storedValue('bedroom_notes', $rooms[0]->id));
        $this->assertSame('İkinci yataq', $this->storedValue('bedroom_notes', $rooms[1]->id));
        $this->assertStringContainsString('İkinci yataq', $this->html('room_bedroom', $rooms[1]->id));
    }

    public function test_file_answer_is_stored_on_the_public_disk_and_shown_back(): void
    {
        // Fayl sualı yalnız «planım var» cavabından sonra görünür.
        $this->save('has_measurement_plan', 'yes')->assertOk();

        $question = $this->question('measurement_plan_file');

        $this->customer()
            ->from(route('portal.brief.section', [$this->studio->project->id, $question->brief_section_id]))
            ->post(route('portal.brief.upload', [$this->studio->project->id, $question->brief_section_id]), [
                'question_id' => $question->id,
                'file' => UploadedFile::fake()->create('obmer-plani.pdf', 24, 'application/pdf'),
            ])
            ->assertRedirect(route('portal.brief.section', [$this->studio->project->id, $question->brief_section_id]));

        $stored = $this->brief->answers()->where('brief_question_id', $question->id)->first();
        $this->assertNotNull($stored);
        $this->assertSame('obmer-plani.pdf', $stored->value['name']);
        $this->assertStringStartsWith('brief/'.$this->brief->id.'/', $stored->value['path']);
        $this->assertTrue(
            Storage::disk('public')->exists($stored->value['path']),
            'Yüklənən fayl `public` diskdə olmalıdır.',
        );
        $this->assertTrue($stored->isAnswered());

        $block = $this->block($this->html('object'), $question);
        $this->assertStringContainsString('obmer-plani.pdf', $block);
        $this->assertStringContainsString('data-file-input', $block);

        // Yalnız `file` tipli suala fayl yazıla bilər.
        $this->customer()
            ->post(route('portal.brief.upload', [$this->studio->project->id, $this->question('object_address')->brief_section_id]), [
                'question_id' => $this->question('object_address')->id,
                'file' => UploadedFile::fake()->create('yad.pdf', 5, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    // ═════════════ 2. Hədlər və uyğunsuz dəyərlər ═════════════

    public function test_text_answers_are_capped_at_one_thousand_characters(): void
    {
        $limit = str_repeat('ə', 1000);

        $this->save('object_address', $limit)->assertOk();
        $this->assertSame($limit, $this->storedValue('object_address'));

        $this->save('object_address', str_repeat('ə', 1001))->assertStatus(422);
        $this->assertSame($limit, $this->storedValue('object_address'), 'Hədd aşılanda köhnə cavab qalmalıdır.');
    }

    public function test_textarea_answers_are_capped_at_five_thousand_characters(): void
    {
        $limit = str_repeat('a', 5000);

        $this->save('special_requests', $limit)->assertOk();
        $this->assertSame($limit, $this->storedValue('special_requests'));

        $this->save('special_requests', str_repeat('a', 5001))->assertStatus(422);
        $this->assertSame($limit, $this->storedValue('special_requests'));
    }

    public function test_multiselect_lists_are_capped_at_two_hundred_items(): void
    {
        $question = $this->question('lead_source');

        // Dəyərlər bankdakı variantlardan gəlir — burada yoxlanılan MƏHZ uzunluq həddidir.
        $this->autosave($question, array_fill(0, 200, 'instagram'))->assertOk();
        $this->assertCount(200, $this->brief->answers()->where('brief_question_id', $question->id)->first()->value);

        $this->autosave($question, array_fill(0, 201, 'instagram'))->assertStatus(422);
        $this->assertCount(
            200,
            $this->brief->answers()->where('brief_question_id', $question->id)->first()->value,
            'Hədd aşılanda köhnə siyahı qalmalıdır.',
        );
    }

    public function test_composite_answers_are_capped_at_twenty_thousand_characters_of_json(): void
    {
        $question = $this->question('household_members');

        // Bir sətir ~ JSON-da sabit uzunluqdadır; həddi mətnin özü ilə dəqiq tuturuq.
        $fits = [['role' => str_repeat('x', 19000)]];
        $this->assertLessThan(20000, strlen((string) json_encode($fits)));
        $this->autosave($question, $fits)->assertOk();
        $this->assertSame($fits, $this->brief->answers()->where('brief_question_id', $question->id)->first()->value);

        $tooBig = [['role' => str_repeat('x', 21000)]];
        $this->assertGreaterThan(20000, strlen((string) json_encode($tooBig)));
        $this->autosave($question, $tooBig)->assertStatus(422);
        $this->assertSame($fits, $this->brief->answers()->where('brief_question_id', $question->id)->first()->value);
    }

    public function test_scalar_types_refuse_values_that_do_not_match_the_type(): void
    {
        // number — mətn deyil.
        $this->save('total_area_sqm', 'yüz otuz')->assertStatus(422);
        $this->assertNull($this->storedValue('total_area_sqm'));

        // date — tanınmayan sətir.
        $this->save('desired_start_date', 'nə vaxtsa')->assertStatus(422);
        $this->assertNull($this->storedValue('desired_start_date'));

        // boolean / consent — yalnız 0|1.
        $this->save('has_pets', '2')->assertStatus(422);
        $this->save('pdpa_consent', 'yes')->assertStatus(422);
        $this->assertNull($this->storedValue('has_pets'));
        $this->assertNull($this->storedValue('pdpa_consent'));

        // text — massiv deyil.
        $this->save('object_address', ['Bakı'])->assertStatus(422);
        $this->assertNull($this->storedValue('object_address'));

        // multiselect — assosiativ massiv deyil, siyahı olmalıdır.
        $this->save('lead_source', ['a' => 'instagram'])->assertStatus(422);
        $this->assertNull($this->storedValue('lead_source'));
    }

    public function test_option_values_that_do_not_belong_to_the_question_are_refused(): void
    {
        $this->save('object_type', 'castle')->assertStatus(422);
        $this->assertNull($this->storedValue('object_type'));

        $this->save('lead_source', ['instagram', 'telepathy'])->assertStatus(422);
        $this->assertNull($this->storedValue('lead_source'));

        $this->save('style_preferences', ['neoclassic', 'brutalism'])->assertStatus(422);
        $this->assertNull($this->storedValue('style_preferences'));

        // image_rating: yad kart və yad hökm.
        $this->save('color_combinations', ['combo_999' => 'like'])->assertStatus(422);
        $this->save('color_combinations', ['combo_1' => 'love'])->assertStatus(422);
        $this->assertNull($this->storedValue('color_combinations'));
    }

    /**
     * Tərkibli tiplərdə də açarlar sualın öz variant siyahısından gəlməlidir.
     *
     * Skript belə açar göndərmir; onları yalnız sorğuya birbaşa müdaxilə edən
     * şəxs göndərə bilər. Yoxlama olmasa uydurma açar bazaya düşür və oradan
     * dizaynerin ekranına, brif PDF-inə və texniki tapşırığa etiketsiz «xam»
     * sətir kimi çıxır.
     */
    public function test_composite_types_refuse_keys_that_do_not_belong_to_the_question(): void
    {
        // matrix: sətir və sütun bankdakı siyahıdan olmalıdır.
        $this->save('contractor_matrix', ['uydurma_setir' => 'own'])->assertStatus(422);
        $this->save('contractor_matrix', ['crew' => 'uydurma_sutun'])->assertStatus(422);
        $this->assertNull($this->storedValue('contractor_matrix'));

        // room_inventory: yalnız mövcud otaq tipləri.
        $this->save('room_inventory', ['dungeon' => 3])->assertStatus(422);
        $this->assertNull($this->storedValue('room_inventory'));

        // std_or_custom: sətir açarı `options.items`-dən olmalıdır.
        $this->save('furniture_heights', ['uydurma_element' => ['mode' => 'std']])->assertStatus(422);
        $this->assertNull($this->storedValue('furniture_heights'));

        // repeater: sütun açarı `options.fields`-dən olmalıdır.
        $this->save('household_members', [['uydurma_sutun' => 'x']])->assertStatus(422);
        $this->assertNull($this->storedValue('household_members'));

        // budget_range: yalnız min/max/currency və tanınan valyuta.
        $this->save('project_budget_range', ['min' => '10', 'max' => '20', 'currency' => 'BTC'])->assertStatus(422);
        $this->save('project_budget_range', ['min' => '10', 'gizli' => 'x'])->assertStatus(422);
        $this->assertNull($this->storedValue('project_budget_range'));

        // Düzgün formalar isə keçməlidir.
        $this->save('contractor_matrix', ['crew' => 'own'])->assertOk();
        $this->save('room_inventory', ['bedroom' => 1])->assertOk();
        $this->save('furniture_heights', ['kitchen_worktop' => ['mode' => 'custom', 'value' => '910']])->assertOk();
        $this->save('household_members', [['role' => 'Ata', 'name' => 'Elçin']])->assertOk();
        $this->save('project_budget_range', ['min' => '10000', 'max' => '20000', 'currency' => 'AZN'])->assertOk();
    }

    public function test_delegating_a_question_that_does_not_allow_it_is_refused(): void
    {
        // `object_address` bankda «dizaynerin ixtiyarına» verilməyib.
        $address = $this->question('object_address');
        $this->assertFalse($address->allows_designer_choice);

        $this->autosave($address, null, true)->assertStatus(422);
        $this->assertNull($this->brief->answers()->where('brief_question_id', $address->id)->first());

        // İcazəli sualda isə həvalə işləyir və cavab yeri boş qalır.
        $delegatable = $this->question('pet_zone_needs');
        $this->assertTrue($delegatable->allows_designer_choice);

        $this->autosave($delegatable, 'əvvəlki mətn')->assertOk();
        $this->autosave($delegatable, null, true)->assertOk();

        $stored = $this->brief->answers()->where('brief_question_id', $delegatable->id)->first();
        $this->assertTrue((bool) $stored->delegated_to_designer);
        $this->assertNull($stored->value);
        $this->assertTrue($stored->isAnswered(), 'Həvalə edilmiş sual doldurulmuş sayılır.');
    }

    public function test_a_room_question_needs_a_room_id_and_a_foreign_room_is_refused(): void
    {
        $this->save('room_inventory', ['bedroom' => 1])->assertOk();
        $roomQuestion = $this->question('bedroom_notes');

        // Otaqsız yazı ÜMUMİ təbəqəyə düşüb bütün otaqların skip məntiqini
        // dəyişərdi — endpoint onu bağlamalıdır.
        $this->autosave($roomQuestion, 'Otaqsız')->assertNotFound();
        $this->assertNull($this->storedValue('bedroom_notes'));

        // Yad brifin otağı.
        $otherWorld = StudioWorld::make('brief-qa2-other');
        $otherBrief = app(TenantContext::class)->actingAs(
            $otherWorld->tenant->id,
            fn () => app(BriefService::class)->forProject($otherWorld->project),
        );
        $foreignRoom = $otherBrief->rooms()->create(['room_type' => 'bedroom', 'label' => 'Yad otaq', 'position' => 1]);

        $this->autosave($roomQuestion, 'Yad otağa', false, $foreignRoom->id)->assertNotFound();
        $this->assertSame(0, $this->brief->fresh()->answers()->where('brief_room_id', $foreignRoom->id)->count());
    }

    public function test_a_section_that_belongs_to_another_template_is_refused(): void
    {
        $foreignSection = BriefSection::where('brief_template_id', '!=', $this->brief->brief_template_id)
            ->whereNotNull('brief_template_id')
            ->firstOrFail();

        $this->customer()
            ->get(route('portal.brief.section', [$this->studio->project->id, $foreignSection->id]))
            ->assertNotFound();

        $foreignQuestion = $foreignSection->questions()->firstOrFail();

        $this->customer()->patchJson(
            route('portal.brief.autosave', [$this->studio->project->id, $foreignSection->id]),
            ['question_id' => $foreignQuestion->id, 'value' => 'Kənar cavab', 'delegated' => false],
        )->assertNotFound();

        $this->assertSame(0, $this->brief->fresh()->answers()->count());
    }

    public function test_a_locked_brief_refuses_every_write_and_a_second_whole_brief_submit(): void
    {
        $this->save('object_address', 'İlk ünvan')->assertOk();
        $this->service()->submit($this->brief->fresh(), $this->studio->portalUser);

        $section = $this->section('object');

        // Cavab dəyişmək, bölmə göndərmək və fayl yükləmək — hamısı bağlı.
        $this->save('object_address', 'Dəyişdirilmiş')->assertStatus(403);
        $this->assertSame('İlk ünvan', $this->storedValue('object_address'));

        $this->customer()
            ->post(route('portal.brief.submit', [$this->studio->project->id, $section->id]))
            ->assertStatus(403);

        $this->customer()
            ->post(route('portal.brief.upload', [$this->studio->project->id, $section->id]), [
                'question_id' => $this->question('measurement_plan_file')->id,
                'file' => UploadedFile::fake()->create('plan.pdf', 5, 'application/pdf'),
            ])
            ->assertStatus(403);

        // İkinci dəfə göndərmək yeni versiya, yeni PDF və yeni damğa yaratmır.
        $before = $this->brief->fresh();
        $documents = $this->studio->project->documents()->count();

        $this->customer()->post(route('portal.brief.send', $this->studio->project->id))
            ->assertRedirect(route('portal.brief.sent', $this->studio->project->id));

        $after = $this->brief->fresh();
        $this->assertSame((int) $before->current_version, (int) $after->current_version);
        $this->assertEquals($before->submitted_at, $after->submitted_at);
        $this->assertSame($documents, $this->studio->project->documents()->count());
    }

    // ═════════════ 3. IDOR / izolyasiya ═════════════

    public function test_another_client_of_the_same_studio_reaches_no_brief_endpoint(): void
    {
        $this->save('object_address', 'Gizli ünvan')->assertOk();

        $section = $this->section('object');
        $intruder = $this->actingAs($this->studio->secondPortalUser, 'customer');
        $project = $this->studio->project->id;

        $intruder->get(route('portal.brief', $project))->assertNotFound();
        $intruder->get(route('portal.brief.section', [$project, $section->id]))->assertNotFound();
        $intruder->get(route('portal.brief.summary', $project))->assertNotFound();
        $intruder->get(route('portal.brief.sent', $project))->assertNotFound();
        $intruder->get(route('portal.brief.clarifications', $project))->assertNotFound();

        $intruder->patchJson(route('portal.brief.autosave', [$project, $section->id]), [
            'question_id' => $this->question('object_address')->id,
            'value' => 'Oğurlanmış yazı',
            'delegated' => false,
        ])->assertNotFound();

        $intruder->post(route('portal.brief.upload', [$project, $section->id]), [
            'question_id' => $this->question('measurement_plan_file')->id,
            'file' => UploadedFile::fake()->create('plan.pdf', 5, 'application/pdf'),
        ])->assertNotFound();

        $intruder->post(route('portal.brief.submit', [$project, $section->id]))->assertNotFound();
        $intruder->post(route('portal.brief.send', $project))->assertNotFound();

        $this->assertSame('Gizli ünvan', $this->storedValue('object_address'));
    }

    public function test_a_client_of_another_studio_reaches_no_brief_endpoint(): void
    {
        $other = StudioWorld::make('brief-qa2-studio2');
        $section = $this->section('object');
        $project = $this->studio->project->id;

        $stranger = $this->actingAs($other->portalUser, 'customer');

        $stranger->get(route('portal.brief', $project))->assertNotFound();
        $stranger->get(route('portal.brief.section', [$project, $section->id]))->assertNotFound();
        $stranger->patchJson(route('portal.brief.autosave', [$project, $section->id]), [
            'question_id' => $this->question('object_address')->id,
            'value' => 'Yad studiya',
            'delegated' => false,
        ])->assertNotFound();

        // Bölmə/şablon bankı studiyalar arasında PAYLAŞILANDIR (tenant-a bağlı
        // deyil), ona görə yad studiyanın müştərisi eyni bölmə id-si ilə ÖZ
        // layihəsini aça bilər — bu normaldır. Vacib olan odur ki, yazdığı cavab
        // ÖZ brifinə düşsün, bizim brifə toxunmasın.
        $stranger->get(route('portal.brief.section', [$other->project->id, $section->id]))->assertOk();

        $stranger->patchJson(route('portal.brief.autosave', [$other->project->id, $section->id]), [
            'question_id' => $this->question('object_address')->id,
            'value' => 'Yad studiyanın öz cavabı',
            'delegated' => false,
        ])->assertOk();

        $this->assertSame(0, $this->brief->fresh()->answers()->count(), 'Yad studiyanın yazısı bizim brifə düşməməlidir.');

        $otherBrief = app(TenantContext::class)->actingAs(
            $other->tenant->id,
            fn () => app(BriefService::class)->forProject($other->project),
        );
        $this->assertSame(1, $otherBrief->answers()->count());
    }

    public function test_an_unauthenticated_visitor_is_sent_to_the_portal_login(): void
    {
        $section = $this->section('object');
        $project = $this->studio->project->id;

        $this->get(route('portal.brief', $project))->assertRedirect(route('portal.login'));
        $this->get(route('portal.brief.section', [$project, $section->id]))->assertRedirect(route('portal.login'));
        $this->patchJson(route('portal.brief.autosave', [$project, $section->id]), [
            'question_id' => $this->question('object_address')->id, 'value' => 'x', 'delegated' => false,
        ])->assertStatus(401);
    }

    /**
     * QA TAPINTI (CİDDİ, DÜZƏLİŞ MƏNİM SAHƏMDƏN KƏNARDIR) — brifə yüklənən
     * cavab faylı `public` diskə düşür və onun üçün avtorizasiyalı yükləmə
     * marşrutu YOXDUR: linki bilən kənar şəxs onu sessiyasız aça bilər.
     * Gündəlik fotosu (`portal.diary.photo`) və çat əlavəsi
     * (`portal.chat.attachment`) məhz bu səbəbdən marşrutdan keçirilir —
     * brif cavabı isə keçmir. Bu test mövcud (səhv) vəziyyəti sənədləşdirir.
     */
    public function test_an_uploaded_answer_file_is_public_and_has_no_authorised_download_route(): void
    {
        $this->save('has_measurement_plan', 'yes')->assertOk();
        $question = $this->question('measurement_plan_file');

        $this->customer()
            ->from(route('portal.brief.section', [$this->studio->project->id, $question->brief_section_id]))
            ->post(route('portal.brief.upload', [$this->studio->project->id, $question->brief_section_id]), [
                'question_id' => $question->id,
                'file' => UploadedFile::fake()->create('bti-plani.pdf', 12, 'application/pdf'),
            ])
            ->assertRedirect();

        $path = $this->brief->answers()->where('brief_question_id', $question->id)->first()->value['path'];

        $this->assertSame(
            'public',
            Storage::disk('public')->getVisibility($path),
            'Brif cavabının faylı hazırda ictimai görünürlükdə saxlanılır.',
        );

        $this->assertTrue(app('router')->has('portal.diary.photo'));
        $this->assertTrue(app('router')->has('portal.chat.attachment'));
        $this->assertFalse(
            app('router')->has('portal.brief.answer.download'),
            'Brif cavab faylı üçün avtorizasiyalı yükləmə marşrutu yoxdur — routes/portal.php-də əlavə edilməlidir.',
        );
    }

    // ═════════════ 4. Faiz arifmetikası ═════════════

    /** Brifin faizi = görünən suallar üzrə cavablanmışların payı. */
    private function expectedProgress(): int
    {
        $map = $this->service()->sectionMap($this->brief->fresh(['rooms']));
        $total = $map->sum(fn ($entry) => $entry['question_count']);
        $answered = $map->sum(fn ($entry) => $entry['answered_count']);

        return $total > 0 ? (int) round($answered / $total * 100) : 0;
    }

    private function visibleQuestionCount(): int
    {
        return (int) $this->service()->sectionMap($this->brief->fresh(['rooms']))
            ->sum(fn ($entry) => $entry['question_count']);
    }

    public function test_progress_counts_only_questions_that_skip_logic_actually_shows(): void
    {
        $this->assertSame(0, (int) $this->brief->fresh()->progress);

        $before = $this->visibleQuestionCount();

        // `pet_zone_needs` yalnız «ev heyvanı var» cavabından sonra görünür —
        // ona görə məxrəc ARTIR, faiz isə buna uyğun hesablanır.
        $this->save('has_pets', '1')->assertOk();
        $afterShown = $this->visibleQuestionCount();
        $this->assertSame($before + 1, $afterShown, 'Şərtli sual görünən sual sayına əlavə olunmalıdır.');

        $this->assertSame($this->expectedProgress(), (int) $this->brief->fresh()->progress);

        // «Yox» cavabı sualı yenidən gizlədir — məxrəc geri qayıdır.
        $this->save('has_pets', '0')->assertOk();
        $this->assertSame($before, $this->visibleQuestionCount());
        $this->assertSame($this->expectedProgress(), (int) $this->brief->fresh()->progress);

        // Otaq açılanda otağın bütün sualları da məxrəcə düşür.
        $roomsBefore = $this->visibleQuestionCount();
        $this->save('room_inventory', ['bedroom' => 1])->assertOk();
        $this->assertGreaterThan($roomsBefore, $this->visibleQuestionCount());
        $this->assertSame($this->expectedProgress(), (int) $this->brief->fresh()->progress);

        // Səhifədə göstərilən rəqəm bazadakı ilə eynidir.
        $index = $this->customer()->get(route('portal.brief', $this->studio->project->id));
        $index->assertOk();
        $index->assertSee($this->brief->fresh()->progress.'%', false);
    }

    public function test_a_stale_progress_column_self_heals_when_the_brief_is_open(): void
    {
        $this->save('object_address', 'Bakı')->assertOk();
        $real = (int) $this->brief->fresh()->progress;
        $this->assertLessThan(100, $real);

        // Kilidi açılmış köhnə briflərdə sütun göndəriş anındakı 100%-i daşıyırdı.
        $this->brief->forceFill(['progress' => 100])->save();

        $this->customer()->get(route('portal.brief', $this->studio->project->id))->assertOk();

        $this->assertSame($real, (int) $this->brief->fresh()->progress, 'Açıq brifdə faiz özünü düzəltməlidir.');
        $this->assertSame($this->expectedProgress(), (int) $this->brief->fresh()->progress);
    }

    public function test_a_locked_brief_keeps_the_progress_it_was_submitted_with(): void
    {
        $this->fillAllRequired();
        $this->customer()->post(route('portal.brief.send', $this->studio->project->id))->assertRedirect();

        $this->assertTrue($this->brief->fresh()->isLocked());

        // Kilidli brifdə sütun lövhə üçün tarixi dəyərdir — baxış onu dəyişməməlidir.
        $this->brief->forceFill(['progress' => 73])->save();
        $this->customer()->get(route('portal.brief', $this->studio->project->id))->assertOk();

        $this->assertSame(73, (int) $this->brief->fresh()->progress);
    }

    public function test_submitting_the_brief_with_unanswered_required_questions_is_refused_with_a_message(): void
    {
        $this->save('object_address', 'Bakı')->assertOk();

        $missing = $this->service()->missingRequired($this->brief->fresh());
        $this->assertGreaterThan(0, $missing->count());

        $response = $this->customer()
            ->from(route('portal.brief.summary', $this->studio->project->id))
            ->post(route('portal.brief.send', $this->studio->project->id));

        $response->assertRedirect(route('portal.brief.summary', $this->studio->project->id));
        $response->assertSessionHasErrors('brief');

        $error = session('errors')->first('brief');
        $this->assertNotSame('', $error);
        $this->assertStringNotContainsString('portal.', $error, 'Xəta mesajı xam tərcümə açarı olmamalıdır.');

        $this->assertFalse($this->brief->fresh()->isLocked(), 'Brif kilidlənməməlidir.');
        $this->assertNull($this->brief->fresh()->submitted_at);

        // Bölmə göndərişi də eyni şəkildə imtina edir və bölmə damğalanmır.
        $section = $this->section('contacts');
        $sectionResponse = $this->customer()
            ->from(route('portal.brief.section', [$this->studio->project->id, $section->id]))
            ->post(route('portal.brief.submit', [$this->studio->project->id, $section->id]));

        $sectionResponse->assertSessionHasErrors('section');
        $this->assertSame(0, $this->brief->sectionStates()->where('brief_section_id', $section->id)->count());
    }

    /** Bütün görünən məcburi suallar HTTP üzərindən cavablanır. */
    private function fillAllRequired(): void
    {
        for ($guard = 0; $guard < 8; $guard++) {
            $missing = $this->service()->missingRequired($this->brief->fresh());

            if ($missing->isEmpty()) {
                return;
            }

            foreach ($missing as $entry) {
                /** @var BriefQuestion $question */
                $question = $entry['question'];

                $value = match ($question->key) {
                    'total_area_sqm' => '120',
                    'design_area_sqm' => '80',
                    'contact_email' => 'qa2@example.test',
                    'contact_phone' => '+994501112233',
                    'room_inventory' => ['bedroom' => 1],
                    default => match ($question->type) {
                        'text' => 'QA mətn',
                        'textarea' => 'QA uzun mətn',
                        'number' => '10',
                        'date' => '2026-06-01',
                        'boolean', 'consent' => '1',
                        'select', 'image_select' => $question->options[0]['value'] ?? null,
                        'multiselect', 'image_multiselect' => [$question->options[0]['value'] ?? null],
                        default => 'QA',
                    },
                };

                $this->autosave($question, $value, false, $entry['room']?->id)->assertOk(
                    'Məcburi sual cavablanmalıdır: '.$question->key,
                );
            }
        }

        $this->fail('Məcburi suallar 8 dövrədə bitmədi.');
    }

    // ═════════════ 5. Saxla / davam et ═════════════

    public function test_a_partially_filled_section_survives_leaving_and_returning(): void
    {
        $this->save('object_address', 'Bakı, Xaqani 12')->assertOk();
        $this->save('object_type', 'apartment')->assertOk();
        $this->save('lead_source', ['instagram'])->assertOk();

        // Müştəri çıxır: başqa bölmə, sonra xəritə, sonra geri.
        $this->html('procurement');
        $this->customer()->get(route('portal.brief', $this->studio->project->id))->assertOk();

        $html = $this->html('object');
        $this->assertStringContainsString('value="Bakı, Xaqani 12"', $html);
        $this->assertMatchesRegularExpression(
            '/value="apartment"[^>]*(?:\n[^<]*)*?bg-accent-dark/s',
            $this->block($html, $this->question('object_type')),
        );
        $this->assertMatchesRegularExpression(
            '/value="instagram"[^>]*(?:\n[^<]*)*?bg-accent-dark/s',
            $this->block($this->html('about_you'), $this->question('lead_source')),
        );

        // Təkrar yazılar sətir çoxaltmır (unikal indeks + updateOrCreate).
        $this->save('object_address', 'Bakı, Xaqani 12')->assertOk();
        $this->save('object_address', 'Bakı, Xaqani 14')->assertOk();
        $this->assertSame(
            1,
            $this->brief->fresh()->answers()->where('brief_question_id', $this->question('object_address')->id)->count(),
        );
        $this->assertSame('Bakı, Xaqani 14', $this->storedValue('object_address'));

        // Bölmə göndəriləndən sonra da cavablar yerində qalır.
        $this->assertSame(3, $this->brief->fresh()->answers()->count());
    }

    // ═════════════ 6. Render: etiket, nişan, kliklənə bilən nəzarətlər ═════════════

    /**
     * Ən bahalı regressiya: bir dəfə səhifə açılırdı, amma HEÇ NƏ kliklənmirdi.
     * Ona görə hər sual kartı üçün ayrıca yoxlanılır — etiket, kömək mətni,
     * məcburilik nişanı, HƏQİQİ nəzarət elementi, `pointer-events` tələsinin
     * olmaması və kartın POST edən formanın İÇİNDƏ qalması.
     */
    public function test_every_question_renders_a_label_and_a_usable_control_inside_the_form(): void
    {
        $this->save('has_measurement_plan', 'yes')->assertOk();

        $section = $this->section('object');
        $html = $this->html('object');

        // Kartların hamısı `#briefForm`-un içindədir: kənarda qalan input
        // heç vaxt göndərilmir və autosave-ə də qoşulmur.
        $formStart = strpos($html, '<form id="briefForm"');
        $formEnd = strpos($html, '</form>', $formStart);
        $this->assertNotFalse($formStart);
        $this->assertNotFalse($formEnd);
        $form = substr($html, $formStart, $formEnd - $formStart);

        $this->assertStringContainsString('data-autosave-url', $form);
        $this->assertStringContainsString('data-upload-url', $form);
        $this->assertStringContainsString('name="_token"', $form, 'CSRF tokeni formada olmalıdır.');

        foreach ($section->questions as $question) {
            $block = $this->block($form, $question);
            $label = $question->getTranslation('label', 'az');

            $this->assertStringContainsString($label, $block, 'Etiket yoxdur: '.$question->key);

            if ($help = $question->getTranslation('help', 'az')) {
                $this->assertStringContainsString($help, $block, 'Kömək mətni yoxdur: '.$question->key);
            }

            if ($question->is_required) {
                $this->assertStringContainsString(
                    '<span class="text-danger">*</span>',
                    $block,
                    'Məcburilik nişanı yoxdur: '.$question->key,
                );
            }

            // Kliklənən/yazılan bir element mütləq olmalıdır.
            $this->assertMatchesRegularExpression(
                '/<(input|textarea|select|button)\b/',
                $block,
                'Sualda heç bir nəzarət elementi yoxdur: '.$question->key,
            );

            // Açıq brifdə heç bir sual `pointer-events-none` arxasında qalmamalıdır.
            $this->assertStringNotContainsString(
                'pointer-events-none',
                $block,
                'Sual kliklənə bilmir (pointer-events tələsi): '.$question->key,
            );
            $this->assertStringNotContainsString(
                ' disabled',
                $block,
                'Açıq brifdə nəzarət elementi bağlı olmamalıdır: '.$question->key,
            );

            // Autosave skriptinin bağlandığı nişanlar.
            $this->assertStringContainsString('data-type="'.$question->type.'"', $block);
            $this->assertStringContainsString('data-key="'.$question->key.'"', $block);
        }

        // Bölmə göndərmə düyməsi və autosave skripti var.
        $this->assertStringContainsString('type="submit"', $form);
        $this->assertStringContainsString("document.getElementById('briefForm')", $html);
    }

    public function test_only_a_delegated_question_is_dimmed_behind_pointer_events_none(): void
    {
        $delegatable = $this->question('pet_zone_needs');
        $this->save('has_pets', '1')->assertOk();

        $plain = $this->block($this->html('about_you'), $delegatable);
        $this->assertStringNotContainsString('pointer-events-none', $plain);

        $this->autosave($delegatable, null, true)->assertOk();

        $delegated = $this->block($this->html('about_you'), $delegatable);
        $this->assertStringContainsString('pointer-events-none', $delegated, 'Həvalə edilmiş sualın zonası bağlanmalıdır.');
        $this->assertStringContainsString('opacity-40', $delegated);

        // Qonşu sual isə açıq qalmalıdır — tələ bütün səhifəyə yayılmamalıdır.
        $this->assertStringNotContainsString(
            'pointer-events-none',
            $this->block($this->html('about_you'), $this->question('has_pets')),
        );
    }

    public function test_a_locked_brief_renders_read_only_without_the_autosave_script(): void
    {
        $this->save('object_address', 'Bakı')->assertOk();
        $this->service()->submit($this->brief->fresh(), $this->studio->portalUser);

        $html = $this->html('object');

        $this->assertStringNotContainsString(
            "document.getElementById('briefForm')",
            $html,
            'Kilidli brifdə autosave skripti render olunmamalıdır.',
        );
        $this->assertStringContainsString('disabled', $html, 'Nəzarət elementləri bağlı olmalıdır.');
        $this->assertStringNotContainsString('type="submit"', $this->briefForm($html));

        // Baxış rejimində naviqasiya və dizaynerlə müzakirə işləməyə davam edir.
        $this->assertStringContainsString('data-discuss-open', $html);
    }

    /**
     * QA TAPINTI (ORTA) — DÜZƏLDİLDİ: `$completed` dəyişəni sual dövrəsinin
     * İÇİNDƏ yenidən təyin olunurdu, «bölməni göndər» düyməsi isə dövrədən
     * SONRA həmin dəyişənə baxırdı. Nəticədə kilidli brifdə bölmənin SON sualı
     * dəqiqləşdirmə üçün işarələnmişdisə, düymə görünürdü və ona basan müştəri
     * izahsız 403 səhifəsi alırdı.
     */
    public function test_the_section_submit_button_is_never_offered_on_a_locked_brief(): void
    {
        $this->save('object_address', 'Bakı')->assertOk();
        $this->service()->submit($this->brief->fresh(), $this->studio->portalUser);

        $section = $this->section('object');
        $lastQuestion = $section->questions->last();

        $this->service()->requestClarification(
            $this->brief->fresh(),
            $lastQuestion,
            null,
            $this->studio->user('designer'),
            'Zəhmət olmasa bunu dəqiqləşdirin.',
        );

        $this->assertTrue($this->brief->fresh()->needsClarification());

        $html = $this->html('object');
        $form = $this->briefForm($html);

        // İşarələnmiş sual redaktə olunur...
        $this->assertStringContainsString('data-question="'.$lastQuestion->id.'"', $html);
        $this->autosave($lastQuestion, $this->sampleFor($lastQuestion))->assertOk();

        // ...amma bölmə göndərmə düyməsi kilidli brifdə OLMAMALIDIR.
        $this->assertStringNotContainsString(
            'type="submit"',
            $form,
            'Kilidli brifdə «bölməni göndər» düyməsi göstərilməməlidir — endpoint 403 verir.',
        );

        $this->customer()
            ->post(route('portal.brief.submit', [$this->studio->project->id, $section->id]))
            ->assertStatus(403);
    }

    // ═════════════ 7. Çoxdillilik ═════════════

    public function test_no_raw_translation_keys_leak_into_the_rendered_brief_pages(): void
    {
        $this->fillAllRequired();

        $project = $this->studio->project->id;
        $rooms = $this->brief->fresh()->rooms;

        $pages = [
            'xəritə' => route('portal.brief', $project),
            'yekun baxış' => route('portal.brief.summary', $project),
        ];

        foreach (['about_you', 'object', 'procurement', 'aesthetics', 'rooms_hub', 'contacts'] as $key) {
            $pages['bölmə: '.$key] = route('portal.brief.section', [$project, $this->section($key)->id]);
        }

        if ($rooms->isNotEmpty()) {
            $room = $rooms->first();
            $section = BriefSection::where('brief_template_id', $this->brief->brief_template_id)
                ->where('room_type', $room->room_type)->first();

            if ($section) {
                $pages['otaq bölməsi'] = route('portal.brief.section', [$project, $section->id, $room->id]);
            }
        }

        $this->customer()->post(route('portal.brief.send', $project))->assertRedirect();
        $pages['göndərildi'] = route('portal.brief.sent', $project);

        foreach ($pages as $name => $url) {
            $response = $this->customer()->get($url);
            $response->assertOk();
            $html = $response->getContent();

            // Yalnız mətn kimi görünən açarları axtarırıq — `data-*` atributları
            // və JS sətirləri deyil.
            preg_match_all('/>\s*((?:portal|brief)\.[a-z0-9_.]+)\s*</i', $html, $matches);

            $this->assertSame(
                [],
                array_values(array_unique($matches[1])),
                'Səhifədə xam tərcümə açarı göründü ('.$name.'): '.implode(', ', array_unique($matches[1])),
            );
        }
    }

    /** Sualın tipinə uyğun sadə, etibarlı cavab. */
    private function sampleFor(BriefQuestion $question): mixed
    {
        return match ($question->type) {
            'text' => 'QA mətn',
            'textarea' => 'QA uzun mətn',
            'number' => '7',
            'date' => '2026-06-01',
            'boolean', 'consent' => '1',
            'select', 'image_select' => $question->options[0]['value'] ?? null,
            'multiselect', 'image_multiselect' => [$question->options[0]['value'] ?? null],
            'std_or_custom' => [($question->options['items'][0]['value'] ?? 'x') => ['mode' => 'std']],
            'matrix' => [
                ($question->options['rows'][0]['value'] ?? 'r') => ($question->options['columns'][0]['value'] ?? 'c'),
            ],
            'image_rating' => [($question->options[0]['value'] ?? 'x') => 'like'],
            'repeater' => [[($question->options['fields'][0]['key'] ?? 'x') => 'QA sətri']],
            'budget_range' => ['min' => '1000', 'max' => '2000', 'currency' => 'AZN'],
            'room_inventory' => [($question->options[0]['value'] ?? 'bedroom') => 1],
            default => 'QA',
        };
    }

    /**
     * QA TAPINTI (KİÇİK) — mövcud davranış sənədləşdirilir: brif controller-i
     * `clientProject()` işlədir, `writableClientProject()` işlətmir, ona görə
     * ARXİVLƏNMİŞ layihədə də brif yazıla və göndərilə bilir. Çat və
     * razılaşdırma bunu bağlayır (`ResolvesClientProjects::writableClientProject`,
     * app/Http/Controllers/Portal/Concerns/ResolvesClientProjects.php:50).
     * Məhsul qərarı tələb edir: brif qəsdən açıq qalırsa, bu test gözlənilən
     * davranışı qoruyur; yox, əgər bağlanmalıdır — test 403-ə çevrilməlidir.
     */
    public function test_an_archived_project_still_accepts_brief_writes_today(): void
    {
        $this->studio->project->forceFill(['status' => 'archived'])->save();

        $this->save('object_address', 'Arxiv layihəyə yazı')->assertOk();
        $this->assertSame('Arxiv layihəyə yazı', $this->storedValue('object_address'));

        // Müqayisə üçün: çat həmin layihədə bağlıdır.
        $this->customer()
            ->post(route('portal.chat.send', $this->studio->project->id), ['body' => 'Salam'])
            ->assertStatus(403);
    }

    /**
     * Cavabları POST edən formanın HTML-i.
     *
     * Səhifədə ondan ƏVVƏL də forma var (qabıqdaki «çıxış»), ona görə ilk
     * `</form>`-a qədər kəsmək yanlış nəticə verir — kəsim məhz `#briefForm`-dan
     * başlayır.
     */
    private function briefForm(string $html): string
    {
        $start = strpos($html, '<form id="briefForm"');
        $this->assertNotFalse($start, 'Brif forması səhifədə yoxdur.');

        $end = strpos($html, '</form>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /** İki nişan arasındaki HTML parçası — kart daxilində sətir izolyasiyası üçün. */
    private function slice(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'Başlanğıc nişanı yoxdur: '.$from);

        $end = strpos($html, $to, $start + strlen($from));

        return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
    }

    /** Bankda olmayan tipləri yoxlamaq üçün sual — brifin şablonuna bağlanır. */
    private function makeQuestion(string $key, string $type, ?array $options): BriefQuestion
    {
        return BriefQuestion::create([
            'brief_section_id' => $this->section('aesthetics')->id,
            'key' => $key,
            'label' => ['az' => 'QA '.$type],
            'help' => ['az' => 'QA köməkçi mətn'],
            'type' => $type,
            'options' => $options,
            'is_required' => false,
            'allows_designer_choice' => false,
            'position' => 900,
            'active' => true,
        ]);
    }
}
