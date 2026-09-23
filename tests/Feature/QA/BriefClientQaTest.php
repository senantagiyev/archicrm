<?php

namespace Tests\Feature\QA;

use App\Enums\BriefStatus;
use App\Models\Brief;
use App\Models\BriefAnswer;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\ChatMessage;
use App\Services\Brief\BriefRiskDetector;
use App\Services\Brief\BriefService;
use App\Support\TenantContext;
use Database\Seeders\BriefQuestionBankSeeder;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA — brifin MÜŞTƏRİ tərəfi (portal). Tam doldurma axını, bütün sual tipləri,
 * kilidlənmə/yenidən açılma, IDOR, sərhəd halları və risk detektoru.
 *
 * Bəzi testlər qəsdən MÖVCUD (səhv) davranışı sənədləşdirir — onlar
 * `// QA TAPINTI:` şərhi ilə işarələnib.
 */
class BriefClientQaTest extends TestCase
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

        $this->studio = StudioWorld::make('brief-client-qa');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $this->brief = app(BriefService::class)->forProject($this->studio->project);
        });
    }

    // ───────────────────────── helpers ─────────────────────────

    private function service(): BriefService
    {
        return app(BriefService::class);
    }

    private function section(string $key): BriefSection
    {
        return BriefSection::where('brief_template_id', $this->brief->brief_template_id)
            ->where('key', $key)
            ->firstOrFail();
    }

    /** Question of THIS brief's template (the bank repeats keys across templates). */
    private function question(string $key): BriefQuestion
    {
        return BriefQuestion::whereIn(
            'brief_section_id',
            BriefSection::where('brief_template_id', $this->brief->brief_template_id)->select('id')
        )->where('key', $key)->firstOrFail();
    }

    private function autosave(BriefQuestion $q, mixed $value, bool $delegated = false, ?int $roomId = null)
    {
        return $this->actingAs($this->studio->portalUser, 'customer')->patchJson(
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

    /** First option value of a question, whatever the options shape is. */
    private function firstOption(BriefQuestion $q, string $bucket = 'list'): ?string
    {
        $options = $q->options ?? [];
        $list = match ($bucket) {
            'rows' => $options['rows'] ?? [],
            'columns' => $options['columns'] ?? [],
            'items' => $options['items'] ?? [],
            default => array_is_list($options) ? $options : ($options['items'] ?? []),
        };

        return $list[0]['value'] ?? null;
    }

    /** A plausible, well-formed answer for every type the bank uses. */
    private function sampleValue(BriefQuestion $q): mixed
    {
        return match ($q->type) {
            'text' => 'QA mətn',
            'textarea' => "QA uzun mətn\nikinci sətir",
            'number' => '42',
            'date' => now()->addYear()->toDateString(),
            'boolean' => '1',
            'consent' => '1',
            'select', 'image_select' => $this->firstOption($q),
            'multiselect', 'image_multiselect' => array_filter([$this->firstOption($q)]),
            'budget_range' => ['min' => '10000', 'max' => '50000', 'currency' => 'AZN'],
            'room_inventory' => [$this->firstOption($q) => 1],
            'matrix' => [$this->firstOption($q, 'rows') => $this->firstOption($q, 'columns')],
            'image_rating' => [$this->firstOption($q) => 'like'],
            'std_or_custom' => [$this->firstOption($q, 'items') => ['mode' => 'standard']],
            'repeater' => [[($q->options['fields'][0]['key'] ?? 'x') => 'QA sətri']],
            'color_swatch' => ['base' => ['#ffffff'], 'accent' => ['#000000']],
            'file' => null,
            default => 'QA',
        };
    }

    /** Answers every visible required question over HTTP until nothing is missing. */
    private function fillAllRequired(): void
    {
        for ($guard = 0; $guard < 8; $guard++) {
            $missing = $this->service()->missingRequired($this->brief->fresh());

            if ($missing->isEmpty()) {
                return;
            }

            foreach ($missing as $entry) {
                /** @var BriefQuestion $q */
                $q = $entry['question'];
                $value = match ($q->key) {
                    'total_area_sqm' => '120',
                    'design_area_sqm' => '80',
                    'contact_email' => 'qa@example.test',
                    'contact_phone' => '+994501112233',
                    default => $this->sampleValue($q),
                };

                $this->autosave($q, $value, false, $entry['room']?->id)->assertOk();
            }
        }

        $this->fail('Məcburi suallar 8 dövrədə bağlanmadı.');
    }

    // ═══════════════ 1. Tam doldurma axını ═══════════════

    public function test_first_open_of_a_sent_brief_moves_it_to_in_progress(): void
    {
        $this->brief->forceFill(['status' => BriefStatus::Sent->value])->save();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief', $this->studio->project->id))
            ->assertOk();

        $this->assertSame(BriefStatus::InProgress, $this->brief->fresh()->statusEnum());
    }

    public function test_the_whole_client_journey_from_empty_brief_to_sent(): void
    {
        $customer = $this->actingAs($this->studio->portalUser, 'customer');

        // 1. Brif açılır — boş, faiz sıfır.
        $customer->get(route('portal.brief', $this->studio->project->id))->assertOk();
        $this->assertSame(0, (int) $this->brief->fresh()->progress);

        // 2. Bölmə-bölmə: ilk bölməyə girir, cavab yazır, geri qayıdanda görür.
        $section = $this->section('object');
        $customer->get(route('portal.brief.section', [$this->studio->project->id, $section->id]))->assertOk();

        $this->save('object_address', 'Bakı, Nizami küç. 1')->assertOk()->assertJson(['ok' => true]);

        $again = $customer->get(route('portal.brief.section', [$this->studio->project->id, $section->id]));
        $again->assertOk();
        $this->assertSame(
            'Bakı, Nizami küç. 1',
            $again->viewData('answers')[$this->question('object_address')->id]->value,
            'Geri qayıdanda cavab yerində olmalıdır.',
        );

        // 3. İlk cavabdan sonra status və faiz dəyişir.
        $this->assertSame(BriefStatus::InProgress, $this->brief->fresh()->statusEnum());
        $this->assertGreaterThan(0, (int) $this->brief->fresh()->progress);

        // 4. Bütün məcburi suallar doldurulur.
        $this->fillAllRequired();
        $this->assertTrue($this->service()->missingRequired($this->brief->fresh())->isEmpty());

        // 5. Yekun baxış ekranı — razılıq verilib, konflikt yoxdur.
        $summary = $customer->get(route('portal.brief.summary', $this->studio->project->id));
        $summary->assertOk();
        $this->assertTrue($summary->viewData('consented'));
        $this->assertSame([], $summary->viewData('validationErrors'));

        // 6. Göndərmə.
        $customer->post(route('portal.brief.send', $this->studio->project->id))
            ->assertRedirect(route('portal.brief.sent', $this->studio->project->id));

        $brief = $this->brief->fresh();
        $this->assertSame(BriefStatus::Submitted, $brief->statusEnum());
        $this->assertSame(100, (int) $brief->progress);
        $this->assertNotNull($brief->submitted_at);
        $this->assertSame(1, $brief->current_version);
        $this->assertSame(1, $brief->versions()->count());

        // 7. Təsdiq ekranı açılır və PDF sənədi yaranıb.
        $customer->get(route('portal.brief.sent', $this->studio->project->id))->assertOk();
        $this->assertTrue($this->studio->project->documents()->where('type', 'brief_export')->exists());
    }

    public function test_sending_is_blocked_while_consent_is_missing(): void
    {
        $this->fillAllRequired();

        // Razılığı geri götürürük — yalnız o sahəni boşaldırıq.
        $this->brief->answers()
            ->where('brief_question_id', $this->question('pdpa_consent')->id)
            ->update(['value' => null, 'delegated_to_designer' => false]);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->from(route('portal.brief.summary', $this->studio->project->id))
            ->post(route('portal.brief.send', $this->studio->project->id))
            ->assertRedirect()
            ->assertSessionHasErrors('brief');

        $this->assertFalse($this->brief->fresh()->isLocked());
    }

    public function test_sending_is_blocked_while_a_required_answer_is_missing(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->from(route('portal.brief.summary', $this->studio->project->id))
            ->post(route('portal.brief.send', $this->studio->project->id))
            ->assertRedirect()
            ->assertSessionHasErrors('brief');

        $this->assertSame(BriefStatus::Draft, $this->brief->fresh()->statusEnum());
    }

    public function test_design_area_larger_than_total_area_is_refused_both_inline_and_on_send(): void
    {
        $this->save('total_area_sqm', '100')->assertOk();
        $this->save('design_area_sqm', '150')->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertNull($this->storedValue('design_area_sqm'), 'Rədd edilən dəyər saxlanmamalıdır.');
    }

    // ═══════════════ 2. Bütün sual tipləri ═══════════════

    /**
     * Bankdakı HƏR tip üçün bir sual seçilir, cavab POST edilir, saxlanma və
     * `displayValue()` yoxlanılır.
     */
    public function test_every_question_type_in_the_bank_round_trips_and_renders(): void
    {
        $questions = BriefQuestion::whereIn(
            'brief_section_id',
            BriefSection::where('brief_template_id', $this->brief->brief_template_id)
                ->whereNull('room_type')->select('id')
        )->where('active', true)->get();

        $types = $questions->groupBy('type');
        $checked = [];

        foreach ($types as $type => $group) {
            if ($type === 'file') {
                continue; // ayrıca `upload` endpoint-i ilə işlənir
            }

            /** @var BriefQuestion $q */
            $q = $group->first(fn (BriefQuestion $q) => filled($this->sampleValue($q))) ?? $group->first();
            $value = $this->sampleValue($q);

            if (blank($value)) {
                continue;
            }

            $this->autosave($q, $value)->assertOk("[$type] cavabı qəbul edilməlidir ({$q->key})");

            $stored = $this->brief->answers()->where('brief_question_id', $q->id)->first();
            $this->assertNotNull($stored, "[$type] cavabı saxlanmalıdır ({$q->key})");
            $this->assertTrue($stored->isAnswered(), "[$type] cavabı «doldurulmuş» sayılmalıdır ({$q->key})");

            $this->assertNotSame(
                '',
                trim($q->displayValue($stored->value)),
                "[$type] displayValue() boş qaytarmamalıdır ({$q->key})",
            );

            $checked[] = $type;
        }

        // Bank real olaraq bu tipləri saxlayır — biri yoxa çıxsa test xəbər verir.
        foreach (['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'boolean',
            'budget_range', 'room_inventory', 'matrix', 'image_rating', 'std_or_custom',
            'repeater', 'image_multiselect', 'consent'] as $expected) {
            $this->assertContains($expected, $checked, 'Tip yoxlanmadı: '.$expected);
        }
    }

    public function test_boolean_and_consent_render_human_labels(): void
    {
        $boolean = BriefQuestion::where('type', 'boolean')->firstOrFail();
        $this->assertSame(t('portal.yes'), $boolean->displayValue('1'));
        $this->assertSame(t('portal.no'), $boolean->displayValue('0'));

        $consent = $this->question('pdpa_consent');
        $this->assertSame('✓', $consent->displayValue('1'));
    }

    /**
     * QA TAPINTI (ORTA) — DÜZƏLDİLDİ: `autosave` `value`-nu sual tipi və variant
     * siyahısı ilə tutuşdurmurdu; `select` sualına mövcud olmayan variant,
     * `number` sualına isə mətn yazmaq olurdu və həmin dəyər dizaynerin
     * ekranına, brif PDF-inə və texniki tapşırığa olduğu kimi düşürdü.
     * `BriefController::sanitiseAnswer()` artıq uyğunsuz cavabı 422 ilə rədd edir.
     */
    public function test_autosave_refuses_values_that_are_not_valid_options_for_the_question(): void
    {
        $select = $this->question('object_type'); // yalnız apartment|house

        $this->autosave($select, 'ZZZ-mövcud-olmayan')->assertStatus(422);
        $this->assertNull($this->storedValue('object_type'));

        // Rəqəm sualına mətn.
        $this->autosave($this->question('total_area_sqm'), 'yüz kvadrat')->assertStatus(422);
        $this->assertNull($this->storedValue('total_area_sqm'));

        // Tək-seçimli suala massiv.
        $this->autosave($select, ['a', 'b'])->assertStatus(422);
        $this->assertNull($this->storedValue('object_type'));

        // Düzgün variant isə normal yazılır — yoxlama real axını bağlamır.
        $this->autosave($select, 'apartment')->assertOk();
        $this->assertSame('apartment', $this->storedValue('object_type'));
    }

    /**
     * QA TAPINTI (CİDDİ) — DÜZƏLDİLDİ: `delegated` bayrağı sualın
     * `allows_designer_choice` dəyəri ilə yoxlanmırdı, ona görə müştəri
     * `delegated: true` göndərərək İSTƏNİLƏN məcburi sualı «cavablanmış» edib
     * brifi boş ünvanla göndərə bilirdi.
     */
    public function test_a_required_non_delegatable_question_cannot_be_bypassed_with_the_delegated_flag(): void
    {
        $q = $this->question('object_address');
        $this->assertTrue($q->is_required);
        $this->assertFalse($q->allows_designer_choice, 'Bank bu sualı dizaynerə həvalə edilə bilməyən kimi işarələyib.');

        $this->autosave($q, null, true)->assertStatus(422);

        $this->assertNull($this->brief->answers()->where('brief_question_id', $q->id)->first());

        $missingKeys = $this->service()->missingRequired($this->brief->fresh())
            ->map(fn ($e) => $e['question']->key)->all();

        $this->assertContains('object_address', $missingKeys, 'Məcburi sual həvalə ilə bağlana bilməz.');
    }

    /** Həvaləyə icazə verilən sualda bayraq əvvəlki kimi işləməlidir. */
    public function test_a_delegatable_question_still_accepts_the_delegated_flag(): void
    {
        $q = BriefQuestion::where('allows_designer_choice', true)->firstOrFail();

        $this->autosave($q, null, true)->assertOk();

        $stored = $this->brief->answers()->where('brief_question_id', $q->id)->first();
        $this->assertTrue($stored->delegated_to_designer);
        $this->assertTrue($stored->isAnswered());
    }

    public function test_the_designer_option_cancels_sibling_picks_in_a_multiselect(): void
    {
        $this->save('wall_materials', ['paint', 'designer'])->assertOk();
        $this->assertSame(['designer'], $this->storedValue('wall_materials'));

        // Tərsi: «designer» tək seçiləndə də eyni nəticə.
        $this->save('wall_materials', ['designer'])->assertOk();
        $this->assertSame(['designer'], $this->storedValue('wall_materials'));
    }

    // ═══════════════ 3. Qismən doldurma və davam ═══════════════

    public function test_partial_filling_marks_the_section_in_progress_and_progress_grows(): void
    {
        $section = $this->section('object');

        $this->save('object_address', 'Bakı')->assertOk();

        $state = $this->brief->sectionStates()
            ->where('brief_section_id', $section->id)->whereNull('brief_room_id')->first();

        $this->assertNotNull($state);
        $this->assertSame('in_progress', $state->status);

        $entryOf = fn () => $this->service()->sectionMap($this->brief->fresh())
            ->first(fn ($e) => $e['section']->id === $section->id);

        $first = $entryOf();
        $this->assertSame('in_progress', $first['status']);
        $this->assertGreaterThan(0, $first['progress']);
        $this->assertLessThan(100, $first['progress']);

        $this->save('object_floor', '5-ci')->assertOk();

        $second = $entryOf();
        $this->assertGreaterThan($first['answered_count'], $second['answered_count']);
        $this->assertGreaterThan($first['progress'], $second['progress']);
        $this->assertGreaterThan(0, (int) $this->brief->fresh()->progress, 'Ümumi faiz də hesablanmalıdır.');
    }

    public function test_a_submitted_section_is_recorded_and_the_client_returns_to_the_map(): void
    {
        $section = $this->section('contacts');

        // Bölmənin bütün məcburi sualları.
        foreach ($section->questions as $q) {
            if ($q->is_required) {
                $this->autosave($q, $q->key === 'contact_email' ? 'qa@example.test' : $this->sampleValue($q))->assertOk();
            }
        }

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.submit', [$this->studio->project->id, $section->id]))
            ->assertRedirect(route('portal.brief', $this->studio->project->id));

        $state = $this->brief->sectionStates()->where('brief_section_id', $section->id)->first();
        $this->assertSame('submitted', $state->status);
        $this->assertNotNull($state->submitted_at);
    }

    public function test_submitting_a_section_with_a_missing_required_answer_is_refused(): void
    {
        $section = $this->section('contacts');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->from(route('portal.brief.section', [$this->studio->project->id, $section->id]))
            ->post(route('portal.brief.submit', [$this->studio->project->id, $section->id]))
            ->assertRedirect()
            ->assertSessionHasErrors('section');

        $this->assertSame(0, $this->brief->sectionStates()->where('brief_section_id', $section->id)->count());
    }

    public function test_room_inventory_materialises_room_sections_and_their_answers_are_kept_apart(): void
    {
        $inventory = $this->question('room_inventory');
        $type = $this->firstOption($inventory);

        $this->autosave($inventory, [$type => 2])->assertOk();

        $rooms = $this->brief->fresh()->rooms()->where('room_type', $type)->get();
        $this->assertCount(2, $rooms, 'İki otaq yaradılmalıdır.');

        $roomSection = BriefSection::where('brief_template_id', $this->brief->brief_template_id)
            ->where('room_type', $type)->first();

        if ($roomSection === null) {
            $this->markTestSkipped('Bu otaq tipi üçün ayrıca bölmə yoxdur.');
        }

        // Sərbəst mətn sualı seçilir: cavab artıq sual tipinə görə yoxlanıldığı
        // üçün ixtiyari sətri yalnız mətn sualı qəbul edir.
        $q = $roomSection->questions->firstWhere(fn ($question) => in_array($question->type, ['text', 'textarea'], true))
            ?? $roomSection->questions->first();

        [$first, $second] = in_array($q->type, ['text', 'textarea'], true)
            ? ['Birinci otaq', 'İkinci otaq']
            : [[$this->firstOption($q)], [$this->firstOption($q)]];

        $this->autosave($q, $first, false, $rooms[0]->id)->assertOk();
        $this->autosave($q, $second, false, $rooms[1]->id)->assertOk();

        $this->assertSame($first, $this->brief->answers()
            ->where('brief_question_id', $q->id)->where('brief_room_id', $rooms[0]->id)->first()->value);
        $this->assertSame($second, $this->brief->answers()
            ->where('brief_question_id', $q->id)->where('brief_room_id', $rooms[1]->id)->first()->value);
    }

    /**
     * QA TAPINTI (ORTA) — DÜZƏLDİLDİ: otaq bölməsinin sualına `room_id` OLMADAN
     * cavab göndərmək olurdu. `section()` ekranı otaqsız açılanda 404 verirdi,
     * `autosave` isə eyni yoxlamanı etmirdi: cavab `brief_room_id = null` ilə
     * yazılır, heç bir otağın faizinə düşmür, amma `valuesByKey()`-in ÜMUMİ
     * təbəqəsinə düşdüyü üçün bütün otaqların skip məntiqinə təsir edirdi.
     */
    public function test_a_room_question_cannot_be_answered_without_a_room_id(): void
    {
        $roomSection = BriefSection::where('brief_template_id', $this->brief->brief_template_id)
            ->whereNotNull('room_type')->firstOrFail();
        $q = $roomSection->questions->first();

        // Ekran otaqsız açılmır.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$this->studio->project->id, $roomSection->id]))
            ->assertNotFound();

        // Autosave də eyni qapını bağlayır.
        $this->autosave($q, 'Otaqsız cavab')->assertNotFound();

        $this->assertNull(
            $this->brief->fresh()->answers()->where('brief_question_id', $q->id)->whereNull('brief_room_id')->first(),
            'Otaqsız cavab sətri ümumiyyətlə yaranmamalıdır.',
        );
    }

    public function test_room_count_is_capped_server_side(): void
    {
        $inventory = $this->question('room_inventory');
        $type = $this->firstOption($inventory);

        $this->autosave($inventory, [$type => 50000])->assertOk();

        $this->assertSame(
            BriefService::MAX_ROOMS_PER_TYPE,
            $this->brief->fresh()->rooms()->where('room_type', $type)->count(),
        );
    }

    // ═══════════════ 4. Göndərmədən sonra ═══════════════

    public function test_a_submitted_brief_rejects_further_answer_changes(): void
    {
        $this->save('object_address', 'Bakı, ilk variant')->assertOk();
        $this->service()->submit($this->brief->fresh(), $this->studio->portalUser);

        $this->save('object_address', 'Dəyişdirilmiş ünvan')->assertStatus(403);
        $this->assertSame('Bakı, ilk variant', $this->storedValue('object_address'));

        // Fayl yükləmə də bağlıdır.
        $file = BriefQuestion::where('type', 'file')->first();
        if ($file) {
            $this->actingAs($this->studio->portalUser, 'customer')
                ->post(route('portal.brief.upload', [$this->studio->project->id, $file->brief_section_id]), [
                    'question_id' => $file->id,
                    'file' => UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'),
                ])
                ->assertStatus(403);
        }
    }

    public function test_an_already_submitted_brief_cannot_be_submitted_twice(): void
    {
        $this->fillAllRequired();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.send', $this->studio->project->id))
            ->assertRedirect(route('portal.brief.sent', $this->studio->project->id));

        $firstSubmittedAt = $this->brief->fresh()->submitted_at;
        $documents = $this->studio->project->documents()->count();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.send', $this->studio->project->id))
            ->assertRedirect(route('portal.brief.sent', $this->studio->project->id));

        $brief = $this->brief->fresh();
        $this->assertSame(1, $brief->current_version, 'İkinci göndəriş yeni versiya yaratmamalıdır.');
        $this->assertSame(1, $brief->versions()->count());
        $this->assertEquals($firstSubmittedAt, $brief->submitted_at);
        $this->assertSame($documents, $this->studio->project->documents()->count(), 'İkinci PDF yaranmamalıdır.');
    }

    /**
     * QA TAPINTI (KİÇİK) — DÜZƏLDİLDİ: bölmə-göndərmə endpoint-i brifin kilidli
     * olub-olmadığını yoxlamırdı. Cavablar dəyişmirdi (autosave 403 verir), amma
     * `brief_section_states.submitted_at` yenidən yazılır və menecerə hər dəfə
     * yeni «bölmə göndərildi» bildirişi gedirdi.
     */
    public function test_section_submit_is_refused_on_a_locked_brief(): void
    {
        $this->fillAllRequired();
        $this->service()->submit($this->brief->fresh(), $this->studio->portalUser);
        $this->assertTrue($this->brief->fresh()->isLocked());

        $section = $this->section('contacts');
        $before = $this->brief->sectionStates()->where('brief_section_id', $section->id)->first()->submitted_at;

        $this->travel(2)->minutes();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.submit', [$this->studio->project->id, $section->id]))
            ->assertForbidden();

        $after = $this->brief->sectionStates()->where('brief_section_id', $section->id)->first()->submitted_at;

        $this->assertEquals($before, $after, 'Kilidli brifdə damğa toxunulmamalıdır.');
        $this->assertSame(BriefStatus::Submitted, $this->brief->fresh()->statusEnum());
    }

    public function test_the_sent_screen_is_only_reachable_once_the_brief_is_locked(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.sent', $this->studio->project->id))
            ->assertRedirect(route('portal.brief', $this->studio->project->id));

        $this->service()->submit($this->brief->fresh(), $this->studio->portalUser);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.sent', $this->studio->project->id))
            ->assertOk();
    }

    // ═══════════════ 5. Yenidən açma ═══════════════

    public function test_reopen_returns_write_access_to_the_client_without_losing_answers(): void
    {
        $this->save('object_address', 'Bakı, Nizami 1')->assertOk();
        $this->service()->submit($this->brief->fresh(), $this->studio->portalUser);

        $this->save('object_address', 'Olmaz')->assertStatus(403);

        $versions = $this->brief->fresh()->versions()->count();
        $this->service()->reopen($this->brief->fresh(), $this->studio->user('designer'), 'Otaqlar dəyişir');

        $brief = $this->brief->fresh();
        $this->assertFalse($brief->isLocked());
        $this->assertSame(BriefStatus::InProgress, $brief->statusEnum());
        $this->assertNull($brief->submitted_at);
        $this->assertSame($versions + 1, $brief->versions()->count());
        $this->assertSame('Bakı, Nizami 1', $this->storedValue('object_address'), 'Yenidən açma cavabı silməməlidir.');

        // Müştəri yenidən yaza bilir.
        $this->save('object_address', 'Bakı, Nizami 2')->assertOk();
        $this->assertSame('Bakı, Nizami 2', $this->storedValue('object_address'));

        // Bölmə vəziyyətləri də açılır.
        $this->assertSame(0, $this->brief->sectionStates()->where('status', 'submitted')->count());
    }

    public function test_reopen_closes_open_clarification_requests_and_the_client_can_send_again(): void
    {
        $this->fillAllRequired();
        $this->service()->submit($this->brief->fresh(), $this->studio->portalUser);

        $this->service()->requestClarification(
            $this->brief->fresh(), $this->question('object_address'), null,
            $this->studio->user('designer'), 'Ünvanı dəqiqləşdirin',
        );
        $this->assertSame(1, $this->brief->fresh()->openComments()->count());

        $this->service()->reopen($this->brief->fresh(), $this->studio->user('designer'));
        $this->assertSame(0, $this->brief->fresh()->openComments()->count());

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.send', $this->studio->project->id))
            ->assertRedirect(route('portal.brief.sent', $this->studio->project->id));

        $this->assertSame(3, $this->brief->fresh()->current_version, 'v1 göndəriş, v2 yenidən açma, v3 təkrar göndəriş.');
    }

    public function test_during_clarification_only_the_flagged_question_is_editable(): void
    {
        $this->save('object_address', 'Bakı')->assertOk();
        $this->service()->submit($this->brief->fresh(), $this->studio->portalUser);

        $flagged = $this->question('object_address');
        $this->service()->requestClarification(
            $this->brief->fresh(), $flagged, null, $this->studio->user('designer'), 'Ünvan dəqiq deyil',
        );

        $this->assertSame(BriefStatus::NeedsClarification, $this->brief->fresh()->statusEnum());

        $this->autosave($flagged, 'Bakı, Nəsimi r.')->assertOk();
        $this->autosave($this->question('object_floor'), '7')->assertStatus(403);

        $page = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$this->studio->project->id, $flagged->brief_section_id]));

        $page->assertOk();
        $this->assertSame([$flagged->id], $page->viewData('editableQuestionIds'));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.clarifications', $this->studio->project->id))
            ->assertOk();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.clarifications.send', $this->studio->project->id))
            ->assertRedirect(route('portal.brief.sent', $this->studio->project->id));

        $this->assertSame(BriefStatus::Submitted, $this->brief->fresh()->statusEnum());
        $this->assertSame(0, $this->brief->fresh()->openComments()->count());
    }

    public function test_sending_clarifications_is_refused_when_none_are_open(): void
    {
        $this->service()->submit($this->brief->fresh(), $this->studio->portalUser);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.clarifications.send', $this->studio->project->id))
            ->assertStatus(403);
    }

    // ═══════════════ 6. Müzakirə ═══════════════

    public function test_discussing_a_section_creates_a_chat_message_with_a_deep_link(): void
    {
        $section = $this->section('lighting');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.discuss', [$this->studio->project->id, $section->id]), [
                'note' => 'İşıq ssenarisi barədə sual',
            ])
            ->assertRedirect();

        $message = ChatMessage::where('project_id', $this->studio->project->id)->latest('id')->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('İşıq ssenarisi barədə sual', $message->body);
        $this->assertStringContainsString(
            route('portal.brief.section', [$this->studio->project->id, $section->id]),
            $message->body,
        );
        $this->assertSame(0, $this->brief->fresh()->answers()->count(), 'Müzakirə cavab yaratmamalıdır.');
    }

    public function test_an_empty_discussion_note_is_allowed_but_an_over_long_one_is_not(): void
    {
        $section = $this->section('object');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.discuss', [$this->studio->project->id, $section->id]), ['note' => ''])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->from(route('portal.brief.section', [$this->studio->project->id, $section->id]))
            ->post(route('portal.brief.discuss', [$this->studio->project->id, $section->id]), [
                'note' => str_repeat('a', 2001),
            ])
            ->assertSessionHasErrors('note');

        $this->assertSame(1, ChatMessage::where('project_id', $this->studio->project->id)
            ->where('body', 'like', '%'.route('portal.brief.section', [$this->studio->project->id, $section->id]).'%')
            ->count());
    }

    public function test_a_room_id_from_another_brief_cannot_be_attached_to_a_discussion(): void
    {
        $inventory = $this->question('room_inventory');
        $this->autosave($inventory, [$this->firstOption($inventory) => 1])->assertOk();

        $foreignBrief = app(TenantContext::class)->actingAs(
            $this->studio->tenant->id,
            fn () => $this->service()->forProject($this->studio->otherProject),
        );
        $foreignRoom = $foreignBrief->rooms()->create(['room_type' => 'x', 'label' => 'Yad otaq', 'position' => 1]);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.discuss', [$this->studio->project->id, $this->section('object')->id]), [
                'room_id' => $foreignRoom->id,
            ])
            ->assertNotFound();
    }

    // ═══════════════ 7. Təhlükəsizlik / IDOR ═══════════════

    public function test_an_unauthenticated_visitor_reaches_no_brief_screen(): void
    {
        $project = $this->studio->project->id;
        $section = $this->section('object')->id;

        $this->get(route('portal.brief', $project))->assertRedirect(route('portal.login'));
        $this->get(route('portal.brief.section', [$project, $section]))->assertRedirect(route('portal.login'));
        $this->get(route('portal.brief.summary', $project))->assertRedirect(route('portal.login'));
        $this->patchJson(route('portal.brief.autosave', [$project, $section]), [
            'question_id' => $this->question('object_address')->id, 'value' => 'x', 'delegated' => false,
        ])->assertStatus(401);
        $this->post(route('portal.brief.send', $project))->assertRedirect(route('portal.login'));
    }

    public function test_a_client_cannot_read_or_write_another_clients_brief(): void
    {
        $other = $this->studio->otherProject->id;
        $section = $this->section('object')->id;
        $q = $this->question('object_address');

        $customer = $this->actingAs($this->studio->portalUser, 'customer');

        $customer->get(route('portal.brief', $other))->assertNotFound();
        $customer->get(route('portal.brief.section', [$other, $section]))->assertNotFound();
        $customer->get(route('portal.brief.summary', $other))->assertNotFound();
        $customer->get(route('portal.brief.sent', $other))->assertNotFound();
        $customer->get(route('portal.brief.clarifications', $other))->assertNotFound();

        $customer->patchJson(route('portal.brief.autosave', [$other, $section]), [
            'question_id' => $q->id, 'value' => 'Yad cavab', 'delegated' => false,
        ])->assertNotFound();

        $customer->post(route('portal.brief.submit', [$other, $section]))->assertNotFound();
        $customer->post(route('portal.brief.send', $other))->assertNotFound();
        $customer->post(route('portal.brief.discuss', [$other, $section]))->assertNotFound();

        $this->assertSame(0, BriefAnswer::whereHas(
            'brief', fn ($q) => $q->where('project_id', $other)
        )->count(), 'Yad layihədə heç bir cavab yaranmamalıdır.');
    }

    public function test_a_client_of_another_studio_cannot_touch_this_brief(): void
    {
        $outsider = StudioWorld::make('brief-client-qa-2');

        $this->actingAs($outsider->portalUser, 'customer')
            ->get(route('portal.brief', $this->studio->project->id))
            ->assertNotFound();

        $this->actingAs($outsider->portalUser, 'customer')
            ->patchJson(route('portal.brief.autosave', [$this->studio->project->id, $this->section('object')->id]), [
                'question_id' => $this->question('object_address')->id,
                'value' => 'Yad studiya',
                'delegated' => false,
            ])
            ->assertNotFound();

        $this->assertSame(0, $this->brief->fresh()->answers()->count());
    }

    public function test_a_room_belonging_to_another_brief_is_refused_on_read_and_write(): void
    {
        $foreignBrief = app(TenantContext::class)->actingAs(
            $this->studio->tenant->id,
            fn () => $this->service()->forProject($this->studio->otherProject),
        );
        $foreignRoom = $foreignBrief->rooms()->create(['room_type' => 'bedroom', 'label' => 'Yad otaq', 'position' => 1]);

        $roomSection = BriefSection::where('brief_template_id', $this->brief->brief_template_id)
            ->whereNotNull('room_type')->firstOrFail();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$this->studio->project->id, $roomSection->id, $foreignRoom->id]))
            ->assertNotFound();

        $this->autosave($roomSection->questions->first(), 'Yad otağa cavab', false, $foreignRoom->id)
            ->assertNotFound();

        $this->assertSame(0, $this->brief->fresh()->answers()->count());
    }

    public function test_a_question_that_does_not_belong_to_the_posted_section_is_refused(): void
    {
        $section = $this->section('object');
        $foreignQuestion = $this->question('contact_phone'); // «Kontaktlar» bölməsindəndir

        $this->actingAs($this->studio->portalUser, 'customer')
            ->patchJson(route('portal.brief.autosave', [$this->studio->project->id, $section->id]), [
                'question_id' => $foreignQuestion->id, 'value' => '+994', 'delegated' => false,
            ])
            ->assertNotFound();

        $this->assertNull($this->storedValue('contact_phone'));
    }

    public function test_autosave_rejects_a_missing_or_malformed_payload(): void
    {
        $section = $this->section('object')->id;

        $this->actingAs($this->studio->portalUser, 'customer')
            ->patchJson(route('portal.brief.autosave', [$this->studio->project->id, $section]), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['question_id', 'delegated']);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->patchJson(route('portal.brief.autosave', [$this->studio->project->id, $section]), [
                'question_id' => 999999, 'value' => 'x', 'delegated' => false,
            ])
            ->assertNotFound();
    }

    /**
     * QA TAPINTI (ORTA) — DÜZƏLDİLDİ: `{section}` marşrut bağlaması BriefSection-ı
     * qlobal oxuyurdu — bölmənin brifin ŞABLONUNA aid olub-olmadığı yoxlanmırdı.
     * Müştəri öz layihəsinin URL-inə başqa şablonun (Quick / Kommersiya) bölmə
     * id-sini yazıb o bölməni açır və ora cavab yazırdı; sətir bazada qalır,
     * heç bir ekranda görünmürdü.
     */
    public function test_a_section_from_another_template_is_refused(): void
    {
        $quick = BriefTemplate::where('key', 'quick')->firstOrFail();
        $this->assertNotSame($quick->id, $this->brief->brief_template_id);

        $foreignSection = BriefSection::where('brief_template_id', $quick->id)->firstOrFail();
        $foreignQuestion = $foreignSection->questions->first();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$this->studio->project->id, $foreignSection->id]))
            ->assertNotFound();

        $this->autosave($foreignQuestion, $this->sampleValue($foreignQuestion))->assertNotFound();

        $this->assertSame(
            0,
            $this->brief->fresh()->answers()->where('brief_question_id', $foreignQuestion->id)->count(),
            'Yad şablonun sualına cavab ümumiyyətlə yaranmamalıdır.',
        );
    }

    // ═══════════════ 8. Sərhəd halları ═══════════════

    public function test_unicode_and_emoji_survive_the_round_trip(): void
    {
        $value = 'Şəhər: Bakı «Ağ şəhər» — 🏠🎨 çox işıqlı, ölçü 5×4 m';

        $this->save('object_address', $value)->assertOk();
        $this->assertSame($value, $this->storedValue('object_address'));

        $page = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$this->studio->project->id, $this->section('object')->id]));

        $page->assertOk()->assertSee('🏠🎨', false);
    }

    public function test_html_in_an_answer_is_escaped_everywhere_the_client_sees_it(): void
    {
        $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';

        $this->save('object_address', $payload)->assertOk();
        $this->save('special_requests', $payload)->assertOk();

        $section = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$this->studio->project->id, $this->section('object')->id]));

        $section->assertOk();
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $section->getContent());
        $this->assertStringNotContainsString('<img src=x onerror=', $section->getContent());
        $this->assertStringContainsString('&lt;script&gt;', $section->getContent());

        $summary = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.summary', $this->studio->project->id));

        $summary->assertOk();
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $summary->getContent());

        // Texniki tapşırıq şablonu da eyni `displayValue()`-dan keçir.
        $brief = $this->brief->fresh();
        $html = view('portal.brief.technical-spec', [
            'brief' => $brief,
            'project' => $this->studio->project,
            'version' => 1,
            'map' => $this->service()->sectionMap($brief),
            'answers' => $brief->answers()->with('question')->get(),
            'risks' => app(BriefRiskDetector::class)->detect($brief),
        ])->render();

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * QA TAPINTI (ORTA) — DÜZƏLDİLDİ: mətn cavablarında uzunluq həddi yox idi,
     * 200 000 simvol qəbul edilir və hər brif ekranında, PDF-də yenidən
     * oxunurdu. İndi hədd var; həddin altındakı normal mətn isə keçir.
     */
    public function test_an_extremely_long_answer_is_refused(): void
    {
        $this->save('special_requests', str_repeat('ə', 200000))->assertStatus(422);
        $this->assertNull($this->storedValue('special_requests'));

        $reasonable = str_repeat('ə', 900);
        $this->save('special_requests', $reasonable)->assertOk();
        $this->assertSame($reasonable, $this->storedValue('special_requests'));
    }

    public function test_repeated_saves_of_the_same_question_never_duplicate_a_row(): void
    {
        $q = $this->question('object_address');

        foreach (['A', 'B', 'C', 'D'] as $value) {
            $this->autosave($q, $value)->assertOk();
        }

        $this->assertSame(1, $this->brief->fresh()->answers()->where('brief_question_id', $q->id)->count());
        $this->assertSame('D', $this->storedValue('object_address'), 'Sonuncu yazı qalmalıdır.');

        // Ümumi sualın `room_key` sütunu 0-dır — unikal indeksin işləməsi məhz buna bağlıdır.
        $this->assertSame(0, (int) $this->brief->answers()->where('brief_question_id', $q->id)->first()->room_key);
    }

    public function test_clearing_an_answer_drops_it_out_of_the_progress_count(): void
    {
        $this->save('object_address', 'Bakı')->assertOk();
        $withAnswer = (int) $this->brief->fresh()->progress;

        $this->save('object_address', null)->assertOk();
        $cleared = (int) $this->brief->fresh()->progress;

        $this->assertLessThan($withAnswer, $cleared);
        $this->assertFalse($this->brief->fresh()->answers()
            ->where('brief_question_id', $this->question('object_address')->id)->first()->isAnswered());

        $this->assertContains(
            'object_address',
            $this->service()->missingRequired($this->brief->fresh())->map(fn ($e) => $e['question']->key)->all(),
        );
    }

    public function test_an_empty_composite_answer_does_not_count_as_answered(): void
    {
        $q = $this->question('project_budget_range');

        $this->autosave($q, ['min' => '', 'max' => '', 'currency' => ''])->assertOk();

        $stored = $this->brief->answers()->where('brief_question_id', $q->id)->first();
        $this->assertFalse($stored->isAnswered(), 'Boş massiv cavab sayılmamalıdır.');
        $this->assertSame('', trim($q->displayValue($stored->value), " –\u{2014}"));
    }

    // ═══════════════ 9. Risk detektoru ═══════════════

    public function test_risk_r1_fires_when_the_budget_is_low_for_the_design_area(): void
    {
        $this->save('total_area_sqm', '200')->assertOk();
        $this->save('design_area_sqm', '200')->assertOk();
        $this->save('project_budget_range', ['min' => '1000', 'max' => '5000', 'currency' => 'AZN'])->assertOk();

        $codes = collect(app(BriefRiskDetector::class)->detect($this->brief->fresh()))->pluck('code')->all();

        $this->assertContains('R1', $codes, '200 m² üçün 5 000 büdcə kritik risk olmalıdır.');

        // Kifayət qədər büdcə → risk yoxdur.
        $this->save('project_budget_range', ['min' => '60000', 'max' => '120000', 'currency' => 'AZN'])->assertOk();
        $this->assertNotContains('R1', collect(app(BriefRiskDetector::class)->detect($this->brief->fresh()))->pluck('code')->all());
    }

    public function test_risk_r4_and_r8_fire_on_the_documented_answers(): void
    {
        $this->save('has_measurement_plan', 'no')->assertOk();
        $this->save('demolition_needed', 'yes')->assertOk();
        $this->save('cooperation_scope', 'design_only')->assertOk();

        $codes = collect(app(BriefRiskDetector::class)->detect($this->brief->fresh()))->pluck('code')->all();

        $this->assertContains('R4', $codes);
        $this->assertContains('R8', $codes);

        // «design_procurement» seçiləndə R8 sönür.
        $this->save('cooperation_scope', 'design_procurement')->assertOk();
        $this->assertNotContains('R8', collect(app(BriefRiskDetector::class)->detect($this->brief->fresh()))->pluck('code')->all());
    }

    public function test_risk_r6_catches_designer_choice_mixed_with_concrete_picks(): void
    {
        // Nəzərə al: autosave «designer»i eksklüziv edir, ona görə konflikt yalnız
        // birbaşa yazıda (admin/import) yarana bilər — detektor onu tutmalıdır.
        $this->brief->answers()->updateOrCreate(
            ['brief_question_id' => $this->question('wall_materials')->id, 'brief_room_id' => null],
            ['value' => ['paint', 'designer'], 'delegated_to_designer' => false, 'answered_at' => now()],
        );

        $risks = collect(app(BriefRiskDetector::class)->detect($this->brief->fresh()));

        $this->assertContains('R6', $risks->pluck('code')->all());
        $this->assertSame('critical', $risks->firstWhere('code', 'R6')['level']);
    }

    public function test_an_empty_brief_raises_no_risks_at_all(): void
    {
        $this->assertSame([], app(BriefRiskDetector::class)->detect($this->brief->fresh()));
    }

    public function test_answer_priorities_flag_missing_required_answers_as_critical(): void
    {
        $rows = $this->service()->answerPriorities($this->brief->fresh());

        $address = $rows->firstWhere(fn ($r) => $r['question']->key === 'object_address');

        $this->assertNotNull($address);
        $this->assertSame('critical', $address['priority']);
        $this->assertSame('Məcburi sahə doldurulmayıb', $address['note']);
    }
}
