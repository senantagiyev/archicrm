<?php

namespace Tests\Feature\QA;

use App\Enums\BriefStatus;
use App\Enums\DocumentType;
use App\Filament\Resources\BriefQuestionResource\Pages\EditBriefQuestion;
use App\Models\Brief;
use App\Models\BriefAnswer;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\User;
use App\Services\Brief\BriefRiskDetector;
use App\Services\Brief\BriefService;
use App\Support\TenantContext;
use Database\Seeders\BriefQuestionBankSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA — Brif: sual bankının bütövlüyü + dizayner/admin tərəfi.
 *
 * Bu fayl YALNIZ yoxlayır, heç nəyi düzəltmir. Tapılan qüsurlar
 * `// QA TAPINTI:` şərhi ilə işarələnib və testlər faktiki (cari) davranışı
 * təsbit edir ki, düzəliş ediləndə qırmızı yansın.
 */
class BriefAdminQaTest extends TestCase
{
    use RefreshDatabase;

    /** section.blade.php-də renderer-i olan tiplər (+ @default → mətn sahəsi). */
    private function renderableTypes(): array
    {
        $blade = file_get_contents(resource_path('views/portal/brief/section.blade.php'));
        preg_match_all("/@case\('([a-z_]+)'\)/", $blade, $m);

        // @default bloku `input type=text` verir — bank «text» tipini məhz ona güvənir.
        return array_merge($m[1], ['text']);
    }

    // ──────────────────────────── 1. BANK BÜTÖVLÜYÜ ────────────────────────────

    /**
     * Sual-sual tam sweep: 352 sualın hamısı üçün tip/label/variant yoxlaması.
     * Problem tapılsa siyahı bütövlükdə mesajda çıxır.
     */
    public function test_every_question_in_the_bank_is_structurally_sound(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $renderable = $this->renderableTypes();
        $optionTypes = ['select', 'multiselect', 'image_select', 'image_multiselect', 'image_rating'];

        $problems = [];
        $checked = 0;

        foreach (BriefQuestion::with('section')->orderBy('brief_section_id')->orderBy('position')->get() as $q) {
            $checked++;
            $where = $q->section->key.'/'.$q->key;

            if (! in_array($q->type, $renderable, true)) {
                $problems[] = "[BLOKER] renderer-siz tip: {$where} ({$q->type})";
            }

            if (blank($q->getTranslation('label', 'az'))) {
                $problems[] = "[BLOKER] boş label: {$where}";
            }

            if (! in_array($q->type, $optionTypes, true)) {
                continue;
            }

            $options = $q->options;

            if (! is_array($options) || $options === [] || ! array_is_list($options)) {
                $problems[] = "[BLOKER] {$q->type} sualının variantları yoxdur: {$where}";

                continue;
            }

            $seen = [];
            foreach ($options as $i => $option) {
                if (! is_array($option) || ! isset($option['value']) || (string) $option['value'] === '') {
                    $problems[] = "[BLOKER] variantın value-su yoxdur: {$where}[{$i}]";

                    continue;
                }

                $value = (string) $option['value'];

                if (blank($option['label']['az'] ?? null)) {
                    $problems[] = "[BLOKER] variantın label-ı yoxdur: {$where}[{$value}]";
                }

                if (in_array($value, $seen, true)) {
                    $problems[] = "[BLOKER] eyni sualda təkrar value: {$where}[{$value}]";
                }

                $seen[] = $value;
            }
        }

        $this->assertSame(352, $checked, 'Bankdakı ümumi sual sayı (quick 12 + residential 329 + commercial 11).');
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Sual açarı ŞABLON daxilində unikal olmalıdır — `valuesByKey()` və
     * `switchTemplate()` açarla işləyir, təkrar açar cavabları qarışdırardı.
     *
     * Şablonlar ARASINDA təkrar qəsdəndir (Quick → Premium keçidi açarla
     * aparılır), ona görə yoxlama şablon-şablon gedir.
     */
    public function test_question_keys_are_unique_inside_every_template(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        foreach (BriefTemplate::all() as $template) {
            $keys = BriefQuestion::whereIn(
                'brief_section_id',
                BriefSection::where('brief_template_id', $template->id)->select('id')
            )->pluck('key');

            $dups = $keys->duplicates()->values()->all();

            $this->assertSame([], $dups, "«{$template->key}» şablonunda təkrar sual açarı: ".implode(', ', $dups));
        }

        // Şablonlar arasında qəsdən paylaşılan 12 açar — Quick brifin hamısı.
        $shared = BriefQuestion::selectRaw('key, count(*) c')->groupBy('key')->havingRaw('c > 1')->pluck('key');
        $this->assertCount(12, $shared, 'Quick brifin bütün açarları Premium bankda da olmalıdır (itkisiz keçid).');
    }

    /** Şərti məntiqin hədəf sualı EYNİ şablonda olmalıdır, yoxsa sual heç vaxt görünmür. */
    public function test_skip_logic_targets_exist_in_the_same_template(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $problems = [];
        $rules = 0;

        foreach (BriefTemplate::all() as $template) {
            $sectionIds = BriefSection::where('brief_template_id', $template->id)->pluck('id');
            $questions = BriefQuestion::whereIn('brief_section_id', $sectionIds)->get();
            $keys = $questions->pluck('key')->flip();

            foreach ($questions as $q) {
                $target = $q->skip_logic['question'] ?? null;
                if (blank($target)) {
                    continue;
                }
                $rules++;
                if (! $keys->has($target)) {
                    $problems[] = "{$template->key}/{$q->key} → «{$target}» yoxdur";
                }
            }
        }

        $this->assertGreaterThan(0, $rules, 'Bankda şərti məntiq ümumiyyətlə olmalıdır.');
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    // ──────────────────────────── 2. ŞABLONLAR ────────────────────────────

    /** Heç bir şablonda sualsız (boş) bölmə qalmamalıdır. */
    public function test_no_template_has_an_empty_section(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $empty = BriefSection::query()
            ->whereDoesntHave('questions')
            ->pluck('key')
            ->all();

        $this->assertSame([], $empty, 'Sualsız bölmə: '.implode(', ', $empty));
    }

    /**
     * Hər şablon üçün brif yaradıb bölmə/sual sayını təsbit edir.
     *
     * Otaq bölmələri `room_inventory` cavabı olmadan GÖRÜNMÜR — yeni brifdə
     * residential şablonu 28 bölmədən yalnız 8-ni açır. Bu, gözlənilən
     * davranışdır (otaqlar hub bölməsindən sonra yaranır), amma «29 bölmə
     * gözləyirdim» deyən istifadəçi üçün faktı burada sənədləşdiririk.
     */
    public function test_each_template_opens_with_the_expected_sections(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $expected = [
            // key => [DB-dəki bölmə sayı, DB-dəki sual sayı, yeni brifdə görünən bölmə sayı]
            'quick' => [1, 12, 1],
            'residential' => [28, 329, 9],
            'commercial' => [3, 11, 3],
        ];

        foreach ($expected as $key => [$sections, $questions, $visible]) {
            $template = BriefTemplate::where('key', $key)->firstOrFail();
            $sectionIds = BriefSection::where('brief_template_id', $template->id)->pluck('id');

            $this->assertCount($sections, $sectionIds, "«{$key}» bölmə sayı");
            $this->assertSame(
                $questions,
                BriefQuestion::whereIn('brief_section_id', $sectionIds)->count(),
                "«{$key}» sual sayı",
            );

            $brief = $this->briefOnTemplate($template);
            $this->assertCount(
                $visible,
                app(BriefService::class)->sectionMap($brief),
                "«{$key}» yeni brifdə açılan bölmə sayı",
            );
        }
    }

    /** Otaq bölmələri yalnız `room_inventory` cavabından sonra açılır. */
    public function test_room_sections_appear_only_after_the_room_inventory_is_answered(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $service = app(BriefService::class);
        $brief = $this->briefOnTemplate(BriefTemplate::where('key', 'residential')->firstOrFail());

        $this->assertCount(9, $service->sectionMap($brief));

        $service->syncRooms($brief, ['bedroom' => 2, 'living' => 1]);

        $map = $service->sectionMap($brief->fresh());
        $roomEntries = $map->filter(fn ($e) => $e['room'] !== null);

        $this->assertCount(3, $roomEntries, 'İki yataq otağı + bir qonaq otağı ayrıca bölmə kimi açılmalıdır.');
        $this->assertCount(12, $map);
    }

    // ──────────────────────── 3. PARİTET SƏNƏDİ ────────────────────────

    /**
     * `docs/roomix-brief-parity.md` sənədi 9 məntiqi bölmə sadalayır; bank da
     * eyni 9 açarla qurulub (7-ci «Помещения» DB-də hub + 19 otaq bölməsinə
     * açılır — bu quruluş fərqidir, məzmun fərqi deyil).
     */
    public function test_the_bank_matches_the_nine_sections_named_in_the_parity_doc(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $doc = file_get_contents(base_path('docs/roomix-brief-parity.md'));

        preg_match('/Bölmə sırası \(9\):\s*\n(.+)/u', $doc, $m);
        $this->assertNotEmpty($m, 'Sənəddə «Bölmə sırası (9)» sətri olmalıdır.');

        $documented = array_map('trim', explode('·', str_replace('`', '', $m[1])));
        $this->assertCount(9, $documented);

        $residential = BriefTemplate::where('key', 'residential')->firstOrFail();
        $bankKeys = BriefSection::where('brief_template_id', $residential->id)->pluck('key');

        foreach ($documented as $key) {
            // Sənəddəki «rooms» bankda `rooms_hub` + `room_*` bölmələridir.
            if ($key === 'rooms') {
                $this->assertTrue($bankKeys->contains('rooms_hub'));
                $this->assertGreaterThanOrEqual(
                    18,
                    $bankKeys->filter(fn ($k) => str_starts_with($k, 'room_'))->count(),
                    'Sənəddəki 7-ci bölmə 18 otaq alt-bölməsi sadalayır.',
                );

                continue;
            }

            $this->assertTrue($bankKeys->contains($key), "Sənəddəki «{$key}» bölməsi bankda yoxdur.");
        }

        // QA TAPINTI [KİÇİK]: bank.php-nin başlıq şərhi «325 sual» deyir,
        // faktiki residential sual sayı 329-dur (database/seeders/brief/bank.php:1-13
        // və seeder nəticəsi). Sənəd/şərh sayı yenilənməlidir.
        $this->assertSame(
            329,
            BriefQuestion::whereIn('brief_section_id', $bankKeys->count() ? BriefSection::where('brief_template_id', $residential->id)->pluck('id') : [])->count(),
            'Residential bankdakı faktiki sual sayı.',
        );
    }

    // ──────────────────────── 4. ŞƏKİLLİ SUALLAR ────────────────────────

    /**
     * `image_rating` (rəng kombinasiyaları) və `image_multiselect` (üslublar)
     * ŞƏKİLSİZ də açılmalıdır — bank şəkilləri hardcode etmir, admin sonra
     * yükləyir. Boş slot səhifəni qırsaydı, brif ilk gündən açılmazdı.
     */
    public function test_the_aesthetics_page_renders_with_no_option_images_uploaded(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $studio = StudioWorld::make('img');

        $section = BriefSection::where('key', 'aesthetics')->firstOrFail();

        $rating = BriefQuestion::where('key', 'color_combinations')->firstOrFail();
        $this->assertNull(
            collect($rating->options)->firstWhere('value', 'combo_1')['image_url'] ?? null,
            'Bank şəkilsiz gəlməlidir — bu testin şərti budur.',
        );

        app(TenantContext::class)->actingAs(
            $studio->tenant->id,
            fn () => app(BriefService::class)->forProject($studio->project),
        );

        $this->actingAs($studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$studio->project, $section]))
            ->assertOk()
            ->assertSee('Rəng kombinasiyaları')
            // Şəkil yoxdursa kart palitranın hex zolaqlarından qurulur.
            ->assertSee('#b4a28c', false);
    }

    /**
     * Risk yoxlaması (qüsur TAPILMADI): `BriefQuestionResource::form()`-dakı
     * `Repeater::make('options')` sxemi yalnız `value`, `label`, `image_url`,
     * `images` sahələrini sadalayır
     * (app/Filament/Resources/BriefQuestionResource.php:136-166), amma bankda
     * variantın `colors` açarı da var. Filament repeater-i saxlayanda sxemdə
     * olmayan açarları atsaydı, `color_combinations`-ın 27 kartı rəng zolağını
     * itirər və portalda boş boz kart kimi görünərdi
     * (resources/views/portal/brief/section.blade.php:309-345).
     *
     * Faktiki nəticə: `colors` yerində qalır — regresiya bu testlə qorunur.
     */
    public function test_saving_the_color_rating_question_in_filament_keeps_the_colour_swatches(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $this->asPanelOwner('qa-brief-admin@test.az');

        $question = $this->residentialQuestion('color_combinations');
        $before = collect($question->options)->firstWhere('value', 'combo_1');
        $this->assertNotEmpty($before['colors'] ?? [], 'Bankda kartın rəngləri var.');

        Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()])
            ->assertOk()
            ->call('save')
            ->assertHasNoFormErrors();

        $after = collect($question->fresh()->options)->firstWhere('value', 'combo_1');

        $this->assertSame(
            $before['colors'],
            $after['colors'] ?? [],
            'Formanın saxlanması bankdan gələn rəng zolağını silməməlidir.',
        );
    }

    /**
     * Seeder-in `mergeOptionImages()` mexanizmi — admin yüklədiyi şəkil deploy-da
     * itməməlidir. Yuxarıdakı qüsur da məhz bununla «sağalır»: növbəti seeder
     * işləməsi `colors`-u bankdan geri qaytarır.
     */
    public function test_reseeding_restores_bank_owned_option_fields_and_keeps_uploads(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $question = BriefQuestion::where('key', 'color_combinations')->firstOrFail();
        $options = $question->options;
        $options[0]['image_url'] = 'brief/options/combo1.webp';
        unset($options[0]['colors']);              // panelin itirdiyi sahə
        $question->update(['options' => $options]);

        $this->seed(BriefQuestionBankSeeder::class);

        $after = collect($question->fresh()->options)->firstWhere('value', 'combo_1');

        $this->assertSame('brief/options/combo1.webp', $after['image_url'] ?? null, 'Yüklənmiş şəkil qalmalıdır.');
        $this->assertNotEmpty($after['colors'] ?? [], 'Bankdan gələn rənglər bərpa olunmalıdır.');
    }

    // ──────────────────── 5. TEXNİKİ TAPŞIRIQ (TZ) ────────────────────

    /** TZ-də YALNIZ cavablanmış sətirlər olmalıdır — cavabsız suallar düşmür. */
    public function test_the_technical_spec_contains_only_answered_rows(): void
    {
        $studio = $this->seededStudio('tz-rows');

        $brief = $this->answer($studio, ['object_address' => 'Bakı, Nizami 1']);

        $html = $this->renderSpec($brief);

        $this->assertStringContainsString('Obyektin ünvanı', $html);
        $this->assertStringContainsString('Bakı, Nizami 1', $html);
        // Eyni bölmədəki cavablanmamış sual TZ-yə düşməməlidir.
        $this->assertStringNotContainsString('Tikinti ili', $html);
        // Tamamilə cavabsız bölmə başlığı da olmamalıdır.
        $this->assertStringNotContainsString('Mühəndislik', $html);
    }

    /** Cavabı HTML olan sual TZ-də ekranlaşdırılmalıdır (PDF-ə skript düşməməlidir). */
    public function test_html_in_an_answer_is_escaped_in_the_technical_spec(): void
    {
        $studio = $this->seededStudio('tz-xss');

        $brief = $this->answer($studio, [
            'object_address' => '<script>alert(1)</script><b>Bakı</b>',
        ]);

        $html = $this->renderSpec($brief);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** Cavabsız brif üçün TZ boş, amma etibarlı sənəddir — imza bloku qalır. */
    public function test_an_unanswered_brief_still_produces_a_valid_technical_spec(): void
    {
        Storage::fake('public');
        $studio = $this->seededStudio('tz-empty');

        $brief = app(TenantContext::class)->actingAs(
            $studio->tenant->id,
            fn () => app(BriefService::class)->forProject($studio->project),
        );

        $html = $this->renderSpec($brief);

        $this->assertStringContainsString('TEXNİKİ TAPŞIRIQ v1', $html);
        $this->assertStringContainsString('Sifarişçi', $html);
        $this->assertStringNotContainsString('<h2>', $html, 'Cavab yoxdursa bölmə başlığı da olmamalıdır.');

        $document = app(TenantContext::class)->actingAs(
            $studio->tenant->id,
            fn () => app(BriefService::class)->buildTechnicalSpec($brief),
        );

        $this->assertSame(DocumentType::TechnicalSpec, $document->type);
        $this->assertFalse($document->visible_to_client);
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ. TZ versiyası mövcud sənədlərin SAYI ilə
     * hesablanırdı, ona görə bir TZ sənədi silinəndə növbəti generasiya artıq
     * işlənmiş nömrəni təkrar verirdi və tarixçədə eyni nömrəli iki sənəd
     * qalırdı. Sayğac indi brifin öz sətrindədir və geri qayıtmır.
     */
    public function test_deleting_a_technical_spec_does_not_repeat_the_version_number(): void
    {
        Storage::fake('public');
        $studio = $this->seededStudio('tz-version');
        $brief = $this->answer($studio, ['object_address' => 'Bakı']);

        [$v1, $v2, $v3] = app(TenantContext::class)->actingAs($studio->tenant->id, function () use ($brief) {
            $service = app(BriefService::class);
            $first = $service->buildTechnicalSpec($brief);
            $second = $service->buildTechnicalSpec($brief);
            $second->delete();

            return [$first, $second, $service->buildTechnicalSpec($brief)];
        });

        $this->assertStringContainsString('v1', $v1->title);
        $this->assertStringContainsString('v2', $v2->title);
        $this->assertStringContainsString(
            'v3',
            $v3->title,
            'Silinmiş versiyanın nömrəsi təkrar işlənməməlidir.',
        );
    }

    /** «Dizaynerin ixtiyarına» verilmiş sual TZ-yə cavablanmış kimi düşür. */
    public function test_delegated_answers_appear_in_the_technical_spec(): void
    {
        $studio = $this->seededStudio('tz-deleg');

        $brief = app(TenantContext::class)->actingAs($studio->tenant->id, function () use ($studio) {
            $brief = app(BriefService::class)->forProject($studio->project);
            $brief->answers()->create([
                'brief_question_id' => $this->residentialQuestion('ceiling_height_raw_mm')->id,
                'brief_room_id' => null,
                'value' => null,
                'delegated_to_designer' => true,
                'answered_at' => now(),
            ]);

            return $brief->fresh();
        });

        $html = $this->renderSpec($brief);

        $this->assertStringContainsString('Dizaynerin ixtiyarına buraxılıb', $html);
    }

    // ──────────────────── 6. BRİF SUALI RESURSU (Filament) ────────────────────

    /**
     * Sual siyahısı yalnız şəkil qəbul edən sualları göstərir və sual
     * YARADILMIR/SİLİNMİR — bank git-dədir. Silmə bağlı olduğuna görə
     * «silinmiş sualın cavabları» ssenarisi paneldən yaranmır; bankdan
     * çıxarılan sual isə `active=false` olur və cavabları qalır.
     */
    public function test_a_question_dropped_from_the_bank_is_deactivated_and_keeps_its_answers(): void
    {
        $studio = $this->seededStudio('drop');

        $brief = $this->answer($studio, ['object_address' => 'Bakı, Nizami 1']);
        $question = $this->residentialQuestion('object_address');

        // Bankdan çıxarılma simulyasiyası (seeder `whereNotIn` ilə eyni effekt).
        $question->update(['active' => false]);

        $this->assertSame(
            1,
            BriefAnswer::where('brief_question_id', $question->id)->count(),
            'Deaktiv sualın cavabı silinməməlidir.',
        );

        $map = app(BriefService::class)->sectionMap($brief->fresh());
        $objectEntry = $map->firstWhere(fn ($e) => $e['section']->key === 'object');

        $this->assertFalse(
            $objectEntry['section']->questions->contains('key', 'object_address'),
            'Deaktiv sual sehrbazda görünməməlidir.',
        );
    }

    /**
     * QA TAPINTI [BLOKER] — «Brif şəkilləri» ekranında sualı saxlamaq hər
     * dəfə variant siyahısının SONUNA İKİ BOŞ variant əlavə edir
     * (`{"value": null, "label": null}`), və bu yığılır: 10 → 12 → 14.
     *
     * Səbəb: forma iki repeater saxlayır — `Repeater::make('options')` və
     * gizli `Repeater::make('options.items')`
     * (app/Filament/Resources/BriefQuestionResource.php:87-166); saxlanma
     * anında hər ikisi `$data['options']` altına düşür və
     * `EditBriefQuestion::mutateFormDataBeforeSave()`
     * (app/Filament/Resources/BriefQuestionResource/Pages/EditBriefQuestion.php:26-53)
     * onları `array_values()` ilə düz siyahıya çevirir.
     *
     * Təkrarlama: admin → Sistem → Brif şəkilləri → «Sizə yaxın olan üslublar»
     * → Saxla. Gözlənilən: 10 variant. Faktiki idi: 12, sonuncu ikisi boş; hər
     * saxlanmada daha ikisi əlavə olunurdu (10 → 12 → 14).
     *
     * DÜZƏLDİLDİ: `mutateFormDataBeforeSave()` formatı sualın tipinə görə ayırd
     * edir və nə `value`, nə `label` daşıyan sətirləri atır.
     */
    public function test_saving_a_question_in_the_panel_keeps_the_option_list_intact(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asPanelOwner('qa-brief-opt@test.az');

        $question = $this->residentialQuestion('style_preferences');
        $before = collect($question->options)->pluck('value')->all();
        $this->assertCount(10, $before);

        Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()])
            ->assertOk()
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($before, collect($question->fresh()->options)->pluck('value')->all());

        // Təkrar saxlanma da siyahını böyütmür.
        Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()])->call('save');
        $this->assertCount(10, $question->fresh()->options);
    }

    /**
     * QA TAPINTI [BLOKER] — `std_or_custom` sualını («Mebel hündürlükləri»)
     * admin panelində saxlamaq onun bütün konfiqurasiyasını MƏHV EDİR:
     * `options` = `{"items": [5 sətir]}` → `[[]]`.
     *
     * Səbəb: variantlar `options.items` altındadır, yəni `options` siyahı yox,
     * assosiativ konfiqdir; `EditBriefQuestion::mutateFormDataBeforeSave()`
     * (…/Pages/EditBriefQuestion.php:33-51) isə şərtsiz `array_values()`
     * tətbiq edir və `items` açarını itirir.
     *
     * Təkrarlama: admin → Sistem → Brif şəkilləri → «Mebel hündürlükləri» →
     * Saxla (heç nə dəyişmədən kifayətdir).
     * Gözlənilən: 5 sətir yerində qalır. Faktiki idi: `options` = `[[]]` —
     * sətirlər, standart ölçülər, vahidlər və yüklənmiş şəkillər itirdi.
     *
     * DÜZƏLDİLDİ: `std_or_custom` sualında `options` konfiqdir, siyahı deyil —
     * mutasiya artıq yalnız `items`-i təmizləyir, konfiqi əzmir.
     */
    public function test_saving_the_furniture_heights_question_keeps_its_rows(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asPanelOwner('qa-brief-std@test.az');

        $question = $this->residentialQuestion('furniture_heights');
        $before = $question->options['items'];
        $this->assertCount(5, $before, 'Bankda 5 sətir var.');

        Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()])
            ->assertOk()
            ->call('save')
            ->assertHasNoFormErrors();

        $after = $question->fresh()->options;

        $this->assertCount(5, $after['items'] ?? []);
        $this->assertSame(
            collect($before)->pluck('value')->all(),
            collect($after['items'])->pluck('value')->all(),
        );
        $this->assertSame(900, $after['items'][0]['standard'], 'Standart ölçü qorunmalıdır.');
        $this->assertSame('mm', $after['items'][0]['unit']);
    }

    /**
     * Düzəlişdən sonra əsas tələb: paneldə saxlamaq admin yüklədiyi şəkilləri
     * itirmir və növbəti seeder işləməsi də onları saxlayır — əvvəl `[[]]`-ə
     * çevrilmiş struktur `mergeOptionImages()`-in şəkli tapmasına imkan vermirdi
     * (database/seeders/BriefQuestionBankSeeder.php:246-268).
     */
    public function test_saving_and_reseeding_keep_itemised_uploads(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asPanelOwner('qa-brief-heal@test.az');

        $heights = $this->residentialQuestion('furniture_heights');
        $options = $heights->options;
        $options['items'][0]['images'] = ['brief/inspiration/worktop.webp'];
        $heights->update(['options' => $options]);

        $styles = $this->residentialQuestion('style_preferences');

        Livewire::test(EditBriefQuestion::class, ['record' => $heights->getKey()])->call('save');
        Livewire::test(EditBriefQuestion::class, ['record' => $styles->getKey()])->call('save');

        $this->seed(BriefQuestionBankSeeder::class);

        $this->assertCount(5, $heights->fresh()->options['items'], 'Struktur qorunur.');
        $this->assertSame(
            ['brief/inspiration/worktop.webp'],
            $heights->fresh()->options['items'][0]['images'] ?? [],
            'Yüklənmiş şəkil saxlanma və seed-dən sonra da yerində qalmalıdır.',
        );
        $this->assertCount(10, $styles->fresh()->options, 'Variant siyahısı böyümür.');
    }

    /** Paneldə saxlandıqdan sonra portal səhifəsində boş kart qalmamalıdır. */
    public function test_no_empty_option_reaches_the_portal_page(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $studio = StudioWorld::make('junk-opt');
        $this->asPanelOwner('qa-brief-portal@test.az');

        Livewire::test(EditBriefQuestion::class, [
            'record' => $this->residentialQuestion('color_combinations')->getKey(),
        ])->call('save');

        $this->assertCount(27, $this->residentialQuestion('color_combinations')->options);

        // Admin sessiyası portal sorğusuna qarışmasın.
        auth()->guard('web')->logout();
        $this->app['auth']->forgetGuards();

        app(TenantContext::class)->actingAs(
            $studio->tenant->id,
            fn () => app(BriefService::class)->forProject($studio->project),
        );

        $this->actingAs($studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$studio->project, BriefSection::where('key', 'aesthetics')->firstOrFail()]))
            ->assertOk()
            // Boş variant `data-rating-row=""` kimi render olunurdu — artıq
            // belə sətir ümumiyyətlə yaranmır.
            ->assertDontSee('data-rating-row=""', false);
    }

    // ──────────────────────────── 7. VERSİYALAŞMA ────────────────────────────

    /** Göndəriş → v1; yenidən açma → v2; snapshot bütün cavabları daşıyır. */
    public function test_versions_are_created_on_submit_and_on_reopen_with_a_full_snapshot(): void
    {
        Storage::fake('public');
        $studio = $this->seededStudio('ver');

        $brief = $this->answer($studio, [
            'object_address' => 'Bakı, Nizami 1',
            'total_area_sqm' => '120',
        ]);

        app(TenantContext::class)->actingAs($studio->tenant->id, function () use ($studio, $brief) {
            $service = app(BriefService::class);
            $service->submit($brief);

            $fresh = $brief->fresh();
            $this->assertSame(1, $fresh->versions()->count());
            $this->assertSame(1, (int) $fresh->current_version);

            $snapshot = $fresh->versions()->first()->snapshot;
            $this->assertSame('Bakı, Nizami 1', $snapshot['general']['object_address']['value']);
            $this->assertSame('120', $snapshot['general']['total_area_sqm']['value']);

            $service->reopen($fresh, $studio->staff['designer'] ?? $studio->staff['owner']);

            $reopened = $brief->fresh();
            $this->assertSame(2, $reopened->versions()->count());
            $this->assertSame(2, (int) $reopened->current_version);
            $this->assertSame(BriefStatus::InProgress->value, $reopened->status);
            $this->assertNull($reopened->submitted_at);
        });
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ. `reopen()` brifi redaktəyə açırdı, amma
     * `progress` sahəsini 100%-də saxlayırdı (`submit()` onu 100 etmişdi,
     * `recalculateProgress()` isə çağırılmırdı). Qismən doldurulmuş brif yenidən
     * açılanda müştərinin portalında «100% dolduruldu» yazırdı, bölmələrin özü
     * isə 13%, 0% göstərirdi.
     */
    public function test_reopen_recalculates_the_progress_bar(): void
    {
        Storage::fake('public');
        $studio = $this->seededStudio('ver-progress');

        $brief = $this->answer($studio, ['object_address' => 'Bakı, Nizami 1']);

        app(TenantContext::class)->actingAs($studio->tenant->id, function () use ($studio, $brief) {
            $service = app(BriefService::class);
            $service->submit($brief);
            $service->reopen($brief->fresh(), $studio->staff['designer'] ?? $studio->staff['owner']);
        });

        $this->assertLessThan(
            100,
            (int) $brief->fresh()->progress,
            'Qismən doldurulmuş brif yenidən açılanda faiz 100-də qala bilməz.',
        );

        // Bölmələr isə düzgün şəkildə redaktəyə qaytarılır.
        $this->assertSame(
            ['in_progress'],
            $brief->fresh()->sectionStates()->pluck('status')->unique()->values()->all(),
        );
    }

    // ──────────────────────────── köməkçilər ────────────────────────────

    private function seededStudio(string $slug): StudioWorld
    {
        Storage::fake('public');
        $this->seed(BriefQuestionBankSeeder::class);

        return StudioWorld::make($slug);
    }

    private function briefOnTemplate(BriefTemplate $template): Brief
    {
        $studio = StudioWorld::make('tpl-'.$template->key);

        return app(TenantContext::class)->actingAs($studio->tenant->id, function () use ($studio, $template) {
            $brief = app(BriefService::class)->forProject($studio->project);
            $brief->forceFill(['brief_template_id' => $template->id])->save();

            return $brief->fresh();
        });
    }

    private function asPanelOwner(string $email): User
    {
        $owner = User::create([
            'name' => 'Sahib', 'email' => $email, 'password' => 'secret123', 'role' => 'owner',
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');

        return $owner;
    }

    /** Açar quick + residential şablonlarında təkrarlanır — residential olanı seçirik. */
    private function residentialQuestion(string $key): BriefQuestion
    {
        return BriefQuestion::where('key', $key)
            ->whereIn('brief_section_id', BriefSection::where(
                'brief_template_id',
                BriefTemplate::where('key', 'residential')->value('id'),
            )->select('id'))
            ->firstOrFail();
    }

    /** @param  array<string, string>  $byKey */
    private function answer(StudioWorld $studio, array $byKey): Brief
    {
        return app(TenantContext::class)->actingAs($studio->tenant->id, function () use ($studio, $byKey) {
            $brief = app(BriefService::class)->forProject($studio->project);

            foreach ($byKey as $key => $value) {
                $brief->answers()->create([
                    'brief_question_id' => $this->residentialQuestion($key)->id,
                    'brief_room_id' => null,
                    'value' => $value,
                    'answered_at' => now(),
                ]);
            }

            return $brief->fresh();
        });
    }

    /** TZ-nin HTML-i — PDF-ə getməzdən əvvəlki məzmun. */
    private function renderSpec(Brief $brief): string
    {
        return app(TenantContext::class)->actingAs($brief->project->tenant_id, function () use ($brief) {
            $service = app(BriefService::class);

            return view('portal.brief.technical-spec', [
                'brief' => $brief,
                'project' => $brief->project,
                'version' => 1,
                'map' => $service->sectionMap($brief),
                'answers' => $brief->answers()->with('question')->get(),
                'risks' => app(BriefRiskDetector::class)->detect($brief),
            ])->render();
        });
    }
}
