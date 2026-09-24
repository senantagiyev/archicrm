<?php

namespace Tests\Feature\QA2;

use App\Enums\AccessLevel;
use App\Enums\BriefStatus;
use App\Enums\Domain;
use App\Filament\Resources\BriefQuestionResource;
use App\Filament\Resources\BriefQuestionResource\Pages\EditBriefQuestion;
use App\Filament\Resources\BriefQuestionResource\Pages\ListBriefQuestions;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\BriefAnswersRelationManager;
use App\Models\Brief;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\User;
use App\Services\Brief\BriefRiskDetector;
use App\Services\Brief\BriefService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\BriefQuestionBankSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA2 — Brif: studiya/admin tərəfi.
 *
 * Testlər davranışı FAKTİKİ sürür: Filament formaları `Livewire::test()` ilə
 * açılıb saxlanılır, fayllar həqiqətən yüklənir, servis metodları real brif
 * üzərində işə salınır.
 *
 * İki əsas məqsəd:
 *  1. `options` JSON-unun paneldən keçəndə ZƏRRƏ QƏDƏR dəyişməməsini BÜTÜN
 *     redaktə edilə bilən suallar üzrə sübut etmək (variantların korlanması
 *     əvvəl buraxılmış blokerdir — tək-tək sual yoxlaması onu tutmadı);
 *  2. şəkil yükləmə zəncirinin variantdan diskə və portal URL-inə qədər
 *     həqiqətən işlədiyini təsdiqləmək.
 */
class BriefAdminTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────── 1. OPTIONS JSON BÜTÖVLÜYÜ ───────────────────────

    /**
     * BLOKER REGRESİYA QORUYUCUSU — panelin redaktə edə bildiyi HƏR sual üçün
     * «heç nə dəyişmədən saxla» əməliyyatı `options` JSON-unu eynilə saxlamalıdır.
     *
     * Niyə tək-tək deyil, sweep: əvvəlki iki bloker (boş variantların yığılması
     * və `std_or_custom` konfiqinin məhv olması) məhz ona görə istehsalata düşdü
     * ki, yoxlama bir sual üzərində aparılırdı. Burada resursun göstərdiyi bütün
     * suallar — hər tip və `options`-un hər forması — bir dövrədən keçir, ona görə
     * banka yeni tip əlavə olunanda test onu avtomatik əhatə edir.
     */
    public function test_saving_every_image_capable_question_round_trips_its_options_unchanged(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asStaff('owner', 'qa2-sweep@test.az');

        $questions = BriefQuestionResource::getEloquentQuery()->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(12, $questions->count(), 'Sweep mənalı olsun deyə siyahı boş olmamalıdır.');

        $broken = [];
        $types = [];

        foreach ($questions as $question) {
            $before = $question->options;
            $types[$question->type] = ($types[$question->type] ?? 0) + 1;

            Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()])
                ->call('save')
                ->assertHasNoFormErrors();

            $after = $question->fresh()->options;

            if ($this->normalise($before) !== $this->normalise($after)) {
                $broken[] = sprintf(
                    '%s (%s): %s → %s',
                    $question->key,
                    $question->type,
                    $this->shape($before),
                    $this->shape($after),
                );
            }
        }

        $this->assertSame([], $broken, "Saxlama `options` JSON-unu dəyişdi:\n".implode("\n", $broken));

        // Bankdaki `image_url => null` saxlamadan sonra açar kimi ümumiyyətlə
        // yoxa çıxır. Bu, məzmun fərqi deyil (`$option['image_url'] ?? null` hər
        // iki halda `null` verir), ona görə müqayisə normallaşdırılır — amma
        // fərqin yalnız BU olduğunu da təsbit edirik ki, normallaşdırma real
        // korlanmanı gizlətməsin.
        $colors = $this->residentialQuestion('color_combinations')->fresh();
        $this->assertArrayNotHasKey('image_url', $colors->options[0]);
        $this->assertSame(['value', 'label', 'colors'], array_keys($colors->options[0]));

        // Sweep-in həqiqətən bütün şəkilli formaları əhatə etdiyini təsbit edirik
        // ki, resursun sorğusu daralsa test səssizcə mənasızlaşmasın.
        foreach (['image_multiselect', 'image_rating', 'std_or_custom', 'multiselect'] as $type) {
            $this->assertArrayHasKey($type, $types, "Sweep «{$type}» tipini əhatə etməlidir.");
        }
    }

    /**
     * İKİNCİ saxlama da nəticəni dəyişməməlidir. Əvvəlki blokerin simptomu məhz
     * yığılma idi (10 → 12 → 14 variant), ona görə idempotentlik ayrıca yoxlanılır.
     */
    public function test_a_second_save_is_idempotent_for_every_image_capable_question(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asStaff('owner', 'qa2-sweep2@test.az');

        $broken = [];

        foreach (BriefQuestionResource::getEloquentQuery()->orderBy('id')->get() as $question) {
            Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()])->call('save');
            $first = $question->fresh()->options;

            Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()])->call('save');
            $second = $question->fresh()->options;

            if ($first !== $second) {
                $broken[] = $question->key.' ('.$question->type.')';
            }
        }

        $this->assertSame([], $broken, 'Təkrar saxlama nəticəni dəyişdi: '.implode(', ', $broken));
    }

    /**
     * Bankdan gələn, formada OLMAYAN açarlar (rəng zolaqları, standart ölçülər,
     * vahidlər, etiketlər) saxlamadan sonra da yerində qalmalıdır — portal
     * kartları məhz onlardan qurulur.
     */
    public function test_bank_owned_option_keys_that_are_not_on_the_form_survive_a_save(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asStaff('owner', 'qa2-extra@test.az');

        $colors = $this->residentialQuestion('color_combinations');
        $heights = $this->residentialQuestion('furniture_heights');

        Livewire::test(EditBriefQuestion::class, ['record' => $colors->getKey()])->call('save');
        Livewire::test(EditBriefQuestion::class, ['record' => $heights->getKey()])->call('save');

        $combo = collect($colors->fresh()->options)->firstWhere('value', 'combo_1');
        $this->assertNotEmpty($combo['colors'] ?? [], 'Rəng zolağı formada yoxdur, amma silinməməlidir.');

        $row = $heights->fresh()->options['items'][0] ?? [];
        $this->assertSame(900, $row['standard'] ?? null);
        $this->assertSame('mm', $row['unit'] ?? null);
        $this->assertNotEmpty($row['label']['az'] ?? null, 'Sətrin etiketi formada gizlidir, amma qalmalıdır.');
    }

    // ─────────────────────── 2. VARİANT ŞƏKİLLƏRİ ───────────────────────

    /**
     * Şəkil zənciri UCDAN-UCA: paneldən yüklənən fayl `public` diskinə düşməli
     * və portalın qurduğu `/storage/...` URL-i həmin faylı göstərməlidir. Disk
     * səhv olsaydı (`FILESYSTEM_DISK=local`) fayl `storage/app/private`-a düşər
     * və müştəridə 404 verərdi — `UploadDiskTest` bunu statik yoxlayır, bu test
     * isə faktiki yüklənmiş faylla.
     */
    public function test_an_uploaded_option_image_lands_on_the_public_disk_and_is_readable(): void
    {
        Storage::fake('public');
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asStaff('owner', 'qa2-upload@test.az');

        $question = $this->residentialQuestion('color_combinations');
        $countBefore = count($question->options);

        $form = Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()]);
        $rowKey = $this->firstRowKey($form, 'option_cards');

        $form->set("data.option_cards.{$rowKey}.image_url", [UploadedFile::fake()->image('combo.png', 40, 30)])
            ->call('save')
            ->assertHasNoFormErrors();

        $after = $question->fresh()->options;
        $saved = $after[0]['image_url'] ?? null;

        $this->assertIsString($saved, 'Yüklənən şəkil variantın `image_url` sahəsinə yazılmalıdır.');
        $this->assertStringStartsWith('brief/options/', $saved, 'Şəkil təyin olunmuş qovluğa düşməlidir.');
        $this->assertTrue(Storage::disk('public')->exists($saved), 'Fayl `public` diskində olmalıdır.');

        // Portalın qurduğu URL faktiki fayl yolunu göstərməlidir.
        $this->assertSame('/storage/'.$saved, parse_url(Storage::disk('public')->url($saved), PHP_URL_PATH));

        // Qalan variantlar və bankın sahələri toxunulmamalıdır.
        $this->assertCount($countBefore, $after);
        $this->assertSame('combo_1', $after[0]['value']);
        $this->assertNotEmpty($after[0]['colors'] ?? []);
    }

    /** Şəkli silmək variantın özünü silməməlidir — kart placeholder kimi qalır. */
    public function test_removing_an_option_image_keeps_the_option_itself(): void
    {
        Storage::fake('public');
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asStaff('owner', 'qa2-remove@test.az');

        $question = $this->residentialQuestion('color_combinations');
        $options = $question->options;
        $options[0]['image_url'] = 'brief/options/old.webp';
        $question->update(['options' => $options]);
        Storage::disk('public')->put('brief/options/old.webp', 'x');

        $form = Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()]);
        $rowKey = $this->firstRowKey($form, 'option_cards');

        $form->set("data.option_cards.{$rowKey}.image_url", [])
            ->call('save')
            ->assertHasNoFormErrors();

        $after = $question->fresh()->options;

        $this->assertArrayNotHasKey('image_url', $after[0], 'Boş şəkil sahəsi variantda qalmamalıdır.');
        $this->assertSame('combo_1', $after[0]['value'], 'Variantın özü silinməməlidir.');
        $this->assertNotEmpty($after[0]['colors'] ?? [], 'Rəng zolağı qalmalıdır.');
        $this->assertCount(count($options), $after);
    }

    /**
     * Nümunə qalereyasının SIRASI mənalıdır: birinci şəkil variantın yanındakı
     * kiçik önizləmədir, ona görə sıralama saxlanmalıdır.
     */
    public function test_inspiration_gallery_keeps_the_uploaded_order(): void
    {
        Storage::fake('public');
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asStaff('owner', 'qa2-order@test.az');

        // `supports_inspiration` sualı — variant başına bir NEÇƏ şəkil.
        $question = $this->residentialQuestion('curtains');
        $this->assertTrue((bool) $question->supports_inspiration);

        $form = Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()]);
        $rowKey = $this->firstRowKey($form, 'option_cards');

        $form->set("data.option_cards.{$rowKey}.images", [
            UploadedFile::fake()->image('a.png'),
            UploadedFile::fake()->image('b.png'),
            UploadedFile::fake()->image('c.png'),
        ])->call('save')->assertHasNoFormErrors();

        $images = $question->fresh()->options[0]['images'] ?? [];

        $this->assertCount(3, $images);
        $this->assertSame(array_values($images), $images, 'Siyahı düz (indeksləri sıralı) qalmalıdır.');

        foreach ($images as $path) {
            $this->assertTrue(Storage::disk('public')->exists($path), $path.' diskdə yoxdur.');
        }

        // Yenidən sıralama: mövcud yolları tərsinə yazmaq saxlanmalıdır.
        $reversed = array_reverse($images);

        $form = Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()]);
        $rowKey = $this->firstRowKey($form, 'option_cards');

        $form->set("data.option_cards.{$rowKey}.images", $reversed)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($reversed, $question->fresh()->options[0]['images'] ?? []);
    }

    /**
     * `std_or_custom` («Mebel hündürlükləri») sətirləri də paneldən şəkil qəbul
     * etməlidir — ekranın bu sual üçün YEGANƏ işi məhz odur.
     *
     * Sətirlər `options.items` altındadır; forma onları repeater sətirləri kimi
     * açmalı və yüklənən şəkli həmin sətrin `images` sahəsinə yazmalıdır.
     */
    public function test_itemised_rows_are_editable_and_accept_their_own_gallery(): void
    {
        Storage::fake('public');
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asStaff('owner', 'qa2-items@test.az');

        $question = $this->residentialQuestion('furniture_heights');
        $this->assertCount(5, $question->options['items'], 'Bankda 5 sətir var.');

        $form = Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()]);

        // Forma sətirləri repeater kimi açmalıdır: 5 sətir, hər birində `value`.
        $rows = $this->rows($form, 'option_rows');

        $this->assertCount(5, $rows, 'Sətirlər formada repeater sətri kimi görünməlidir.');
        $this->assertSame(
            ['kitchen_worktop', 'bath_sink', 'rain_shower', 'wc_from_floor', 'desk'],
            collect($rows)->pluck('value')->all(),
            'Sətirlərin sırası və açarları bankdakı kimi olmalıdır.',
        );

        $rowKey = array_key_first($rows);

        $form->set("data.option_rows.{$rowKey}.images", [UploadedFile::fake()->image('worktop.png')])
            ->call('save')
            ->assertHasNoFormErrors();

        $items = $question->fresh()->options['items'];

        $this->assertCount(5, $items, 'Saxlama sətirləri itirməməlidir.');
        $this->assertSame(900, $items[0]['standard'], 'Standart ölçü qorunmalıdır.');
        $this->assertSame('mm', $items[0]['unit']);

        $images = $items[0]['images'] ?? [];
        $this->assertCount(1, $images, 'Yüklənən nümunə şəkli sətrin `images` sahəsinə yazılmalıdır.');
        $this->assertStringStartsWith('brief/inspiration/', $images[0]);
        $this->assertTrue(Storage::disk('public')->exists($images[0]), 'Fayl `public` diskində olmalıdır.');

        // Qalan sətirlər şəkilsiz qalmalıdır — şəkil yad sətrə yapışmamalıdır.
        $this->assertSame([], $items[1]['images'] ?? [], 'Şəkil yalnız seçilmiş sətrə düşməlidir.');
    }

    /** Diskdə qalan, amma bankda artıq olmayan şəkil seeder-dən sonra da qalmalıdır. */
    public function test_an_uploaded_itemised_image_survives_a_reseed(): void
    {
        Storage::fake('public');
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asStaff('owner', 'qa2-items-seed@test.az');

        $question = $this->residentialQuestion('furniture_heights');

        $form = Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()]);
        $rows = $this->rows($form, 'option_rows');
        $rowKey = array_key_first($rows);

        $form->set("data.option_rows.{$rowKey}.images", [UploadedFile::fake()->image('worktop.png')])
            ->call('save')
            ->assertHasNoFormErrors();

        $uploaded = $question->fresh()->options['items'][0]['images'][0] ?? null;
        $this->assertIsString($uploaded);

        $this->seed(BriefQuestionBankSeeder::class);

        $this->assertSame(
            [$uploaded],
            $question->fresh()->options['items'][0]['images'] ?? [],
            'Seeder yüklənmiş şəkli silməməlidir (mergeOptionImages).',
        );
        $this->assertCount(5, $question->fresh()->options['items']);
    }

    /**
     * UCDAN-UCA: paneldən yüklənən şəkil müştərinin gördüyü səhifədə məhz
     * portalın qurduğu URL ilə render olunmalıdır. Diskə düşüb portalda
     * görünməyən şəkil admin üçün «yüklədim, işləmir» deməkdir.
     */
    public function test_an_image_uploaded_in_the_panel_is_rendered_by_the_portal(): void
    {
        Storage::fake('public');
        $this->seed(BriefQuestionBankSeeder::class);
        $studio = StudioWorld::make('portal-img');

        $this->asStaff('owner', 'qa2-portal@test.az');

        $question = $this->residentialQuestion('color_combinations');
        $form = Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()]);

        $form->set('data.option_cards.'.$this->firstRowKey($form, 'option_cards').'.image_url', [
            UploadedFile::fake()->image('combo.png', 40, 30),
        ])->call('save')->assertHasNoFormErrors();

        $path = $question->fresh()->options[0]['image_url'];
        $this->assertTrue(Storage::disk('public')->exists($path));

        // Admin sessiyası portal sorğusuna qarışmasın.
        auth()->guard('web')->logout();
        $this->app['auth']->forgetGuards();

        $this->inTenant($studio->tenant->id, fn () => app(BriefService::class)->forProject($studio->project));

        $this->actingAs($studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$studio->project, BriefSection::where('key', 'aesthetics')->firstOrFail()]))
            ->assertOk()
            ->assertSee(storage_url($path), false);
    }

    /**
     * Siyahıdaki «Şəkilsiz variant» nişanı faktiki vəziyyəti göstərməlidir —
     * admin «nə qalıb» sualının cavabını ondan oxuyur.
     */
    public function test_the_missing_images_badge_reflects_the_actual_state(): void
    {
        Storage::fake('public');
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asStaff('owner', 'qa2-badge@test.az');

        $question = $this->residentialQuestion('color_combinations');
        $total = count($question->options);

        Livewire::test(ListBriefQuestions::class)
            ->assertOk()
            ->assertSee($total.' / '.$total);

        // Bir karta şəkil yükləyirik — nişan bir azalmalıdır.
        $form = Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()]);
        $form->set('data.option_cards.'.$this->firstRowKey($form, 'option_cards').'.image_url', [
            UploadedFile::fake()->image('combo.png'),
        ])->call('save')->assertHasNoFormErrors();

        Livewire::test(ListBriefQuestions::class)
            ->assertOk()
            ->assertSee(($total - 1).' / '.$total);
    }

    /**
     * Şəkil qəbul etməyən sual paneldən REDAKTƏ EDİLƏ BİLMƏZ — resursun sorğusu
     * onu görmür. Əks halda «bank git-dədir» qaydası URL ilə dolanılardı.
     */
    public function test_a_question_outside_the_image_scope_cannot_be_opened_for_editing(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $this->asStaff('owner', 'qa2-scope@test.az');

        $plain = $this->residentialQuestion('object_address');

        $this->get(BriefQuestionResource::getUrl('edit', ['record' => $plain->getKey()]))
            ->assertNotFound();
    }

    // ─────────────────────── 3. İCAZƏLƏR (AccessMatrix) ───────────────────────

    /** Mühasib (Brief = None) sual bankını nə görür, nə də açır — 403, gizlətmə deyil. */
    public function test_a_role_without_the_brief_domain_is_blocked_from_the_question_bank(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $question = $this->residentialQuestion('color_combinations');

        $this->asStaff('accountant', 'qa2-acc@test.az');

        $this->assertFalse(BriefQuestionResource::canViewAny(), 'Mühasib sual bankını görməməlidir.');
        $this->get(BriefQuestionResource::getUrl('index'))->assertForbidden();
        $this->get(BriefQuestionResource::getUrl('edit', ['record' => $question->getKey()]))->assertForbidden();
    }

    /** Vizualizator (Brief = View) siyahını oxuyur, amma şəkilləri dəyişə bilmir. */
    public function test_a_view_only_role_can_read_the_bank_but_not_change_an_image(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $question = $this->residentialQuestion('color_combinations');

        $this->asStaff('visualizer', 'qa2-vis@test.az');

        $this->assertTrue(BriefQuestionResource::canViewAny());
        $this->get(BriefQuestionResource::getUrl('index'))->assertOk();
        $this->assertFalse(
            BriefQuestionResource::canEdit($question),
            'Yalnız baxış səviyyəsi variant şəkillərini dəyişməyə icazə verməməlidir.',
        );
        $this->get(BriefQuestionResource::getUrl('edit', ['record' => $question->getKey()]))->assertForbidden();
    }

    /** Dizayner (Brief = Full) şəkilləri redaktə edə bilir. */
    public function test_a_full_brief_role_may_edit_option_images(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $question = $this->residentialQuestion('color_combinations');

        $this->asStaff('designer', 'qa2-des@test.az');

        $this->assertTrue(BriefQuestionResource::canEdit($question));

        Livewire::test(EditBriefQuestion::class, ['record' => $question->getKey()])
            ->assertOk()
            ->call('save')
            ->assertHasNoFormErrors();
    }

    /** Sual bankında YARATMA və SİLMƏ heç bir rola açıq deyil — bank git-dədir. */
    public function test_nobody_can_create_or_delete_a_brief_question_from_the_panel(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $owner = $this->asStaff('owner', 'qa2-crud@test.az');
        $question = $this->residentialQuestion('color_combinations');

        $this->assertFalse(BriefQuestionResource::canCreate());
        $this->assertFalse(BriefQuestionResource::canDelete($question));
        $this->assertFalse($owner->can('create', BriefQuestion::class));
        $this->assertFalse($owner->can('delete', $question));

        $this->assertArrayNotHasKey('create', BriefQuestionResource::getPages());

        Livewire::test(ListBriefQuestions::class)
            ->assertOk()
            ->assertActionDoesNotExist('create');
    }

    /** Deaktiv edilmiş işçi hesabı brif bankına da girməməlidir. */
    public function test_a_deactivated_staff_account_loses_access_to_the_question_bank(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $designer = $this->asStaff('designer', 'qa2-off@test.az');

        $designer->forceFill(['is_active' => false])->save();
        AccessMatrix::flushCache();

        $this->assertFalse(BriefQuestionResource::canViewAny());
        $this->assertFalse(BriefQuestionResource::canEdit($this->residentialQuestion('color_combinations')));
    }

    /**
     * BLOKER İDİ, DÜZƏLDİLDİ — bu test artıq DOĞRU davranışı qoruyur.
     *
     * «Brif şablonu» əməliyyatı HEÇ KİM üçün saxlanmırdı: seçim həmişə
     * «brief_template_id» validasiya xətası ilə qayıdırdı, yəni brifi Quick ↔
     * Premium arasında keçirmək UI-dan MÜMKÜN DEYİLDİ. `BriefService::switchTemplate()`
     * özü düzgün işləyirdi (aşağıdaki servis testləri bunu göstərir) — qırıq olan
     * yalnız onu çağıran forma idi.
     *
     * Səbəb:
     * app/Filament/Resources/ProjectResource/RelationManagers/BriefAnswersRelationManager.php:84-90
     * variantları `->groupBy(...)->map(fn ($group) => $group->mapWithKeys(...))->all()`
     * ilə qurur. Xarici `->all()` yalnız ÜST səviyyəni massivə çevirir; qrupların
     * özü `Illuminate\Support\Collection` olaraq qalır. Filament-in qruplanmış
     * variant axtarışı isə `is_array($groupedOptions)` yoxlayır
     * (vendor/filament/forms/src/Components/Select.php — `resolveOptionLabel()`),
     * ona görə qrupları ötürür, etiket tapılmır və
     * `Select::getInValidationRuleValues()` BOŞ icazə siyahısı qaytarır → `in:`
     * qaydası hər dəyəri rədd edir.
     *
     * Təkrarlama: panel → layihə → «Brif» tabı → «Brif şablonu» → istənilən
     * şablonu seç → Saxla. Nəticə: «seçilmiş dəyər yanlışdır», şablon dəyişmir.
     *
     * Düzəliş: daxili qrupa da `->all()` tətbiq olundu —
     * `->map(fn ($group) => $group->mapWithKeys(...)->all())`.
     */
    public function test_the_brief_template_switch_action_saves(): void
    {
        $studio = $this->seededStudio('tpl-broken');
        $quick = BriefTemplate::where('key', 'quick')->firstOrFail();

        $brief = $this->inTenant($studio->tenant->id, fn () => app(BriefService::class)->forProject($studio->project));
        $before = $brief->brief_template_id;
        $this->assertNotSame($quick->id, $before, 'Başlanğıc şablon Premium olmalıdır.');

        $this->templateAction($studio, $studio->staff['owner'])
            ->callTableAction('briefTemplate', data: ['brief_template_id' => $quick->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame(
            $quick->id,
            $brief->fresh()->brief_template_id,
            'Şablon paneldən dəyişmədi — qruplu variant siyahısı yenə massiv deyil.',
        );
    }

    /**
     * YÜKSƏK İDİ, DÜZƏLDİLDİ — bu test artıq DOĞRU davranışı qoruyur.
     *
     * «Brif şablonu» əməliyyatı Brief domenindəki səviyyəni YOXLAMIRDI:
     * app/Filament/Resources/ProjectResource/RelationManagers/BriefAnswersRelationManager.php:77-99
     * (`Actions\Action::make('briefTemplate')` — nə `->visible()`, nə
     * `->authorize()`, nə də `AccessMatrix` yoxlaması var; relation manager-in
     * özündə də `canViewForRecord()` yoxdur).
     *
     * Nəticə: layihənin üzvü olan, Brief domenində yalnız BAXIŞ səviyyəsi olan
     * işçiyə (komplektasiya, vizualizator) əməliyyat GÖRÜNÜR. Yuxarıdaki bloker
     * düzəldiləndən sonra bu, birbaşa səlahiyyət deşiyinə çevrilir: şablon dəyişmək
     * cavabların hansı sual dəstinə aid olduğunu, otaq bölmələrini və faizi
     * yenidən qurur. Müqayisə üçün: eyni domenin `BriefReview` səhifəsi
     * (…/Pages/BriefReview.php:50-51) və `BriefQuestionPolicy::update()`
     * səviyyəni düzgün yoxlayır.
     *
     * Gözlənilən (indi tətbiq olunur): əməliyyat görünməməli / 403 —
     * `Domain::Brief` = Full tələbi.
     */
    public function test_a_view_only_role_cannot_see_or_invoke_the_template_switch_action(): void
    {
        $studio = $this->seededStudio('tpl-perm');
        $this->inTenant($studio->tenant->id, fn () => app(BriefService::class)->forProject($studio->project));

        // Komplektasiya işçisi: Brief = View, layihənin üzvüdür.
        $procurement = $studio->staff['procurement'];
        $this->assertFalse(
            AccessMatrix::allows($procurement, Domain::Brief, AccessLevel::Full),
            'Bu rolun brifə tam səlahiyyəti olmamalıdır — testin şərti budur.',
        );

        // Düymə ekranda olmamalıdır…
        $this->assertStringNotContainsString(
            'Brif şablonu',
            $this->templateAction($studio, $procurement)->html(),
            'Yalnız baxış səviyyəli rol «Brif şablonu» düyməsini görür.',
        );

        // …sahibkar isə onu görməlidir (yoxlama hamını kəsməsin).
        $this->assertStringContainsString(
            'Brif şablonu',
            $this->templateAction($studio, $studio->staff['owner'])->html(),
            'Düymə tam səlahiyyətli rol üçün də itdi.',
        );

        // Gizlətmək kifayət deyil: düymənin olmaması Livewire sorğusunu
        // bloklamır, ona görə əməliyyatın ÖZÜ də işləməməlidir.
        $quick = BriefTemplate::where('key', 'quick')->firstOrFail();
        $brief = $this->inTenant($studio->tenant->id, fn () => app(BriefService::class)->forProject($studio->project));
        $before = $brief->brief_template_id;

        try {
            $this->templateAction($studio, $procurement)
                ->callTableAction('briefTemplate', data: ['brief_template_id' => $quick->id]);
        } catch (AssertionFailedError) {
            // Filament gizli əməliyyatı ümumiyyətlə çağırmağa qoymur — bu da
            // gözlənilən nəticədir, testi qırmamalıdır.
        }

        $this->assertSame(
            $before,
            $brief->fresh()->brief_template_id,
            'Yalnız baxış səviyyəli rol brif şablonunu dəyişdi.',
        );
    }

    /** «Brif» relation manager-ini verilmiş işçi kimi açır. */
    private function templateAction(StudioWorld $studio, User $user): Testable
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        AccessMatrix::flushCache();
        app(TenantContext::class)->set($studio->tenant->id);

        return Livewire::test(BriefAnswersRelationManager::class, [
            'ownerRecord' => $studio->project,
            'pageClass' => EditProject::class,
        ]);
    }

    // ─────────────────────── 4. BriefService ───────────────────────

    /**
     * Şablon dəyişəndə cavablar AÇAR üzrə köçməlidir və geri dönüş də itkisiz
     * olmalıdır — Quick → Premium → Quick zənciri müqavilə imzalananda real
     * ssenaridir.
     */
    public function test_switching_the_template_carries_answers_over_by_key_in_both_directions(): void
    {
        $studio = $this->seededStudio('sw');
        $quick = BriefTemplate::where('key', 'quick')->firstOrFail();
        $premium = BriefTemplate::where('key', 'residential')->firstOrFail();

        $brief = $this->inTenant($studio->tenant->id, function () use ($studio, $quick) {
            $brief = app(BriefService::class)->forProject($studio->project);
            $brief->forceFill(['brief_template_id' => $quick->id])->save();

            return $brief->fresh();
        });

        $this->answerOnTemplate($studio, $brief, $quick, [
            'object_address' => 'Bakı, Nizami 1',
            'total_area_sqm' => '120',
        ]);

        $service = app(BriefService::class);

        // Quick → Premium.
        $this->inTenant($studio->tenant->id, fn () => $service->switchTemplate($brief->fresh(), $premium));

        $values = $this->inTenant($studio->tenant->id, fn () => $service->valuesByKey($brief->fresh()));
        $this->assertSame('Bakı, Nizami 1', $values['object_address'] ?? null);
        $this->assertSame('120', $values['total_area_sqm'] ?? null);
        $this->assertSame($premium->id, $brief->fresh()->brief_template_id);

        // Premium → Quick: cavablar yenə görünməlidir (köhnə sətirlər saxlanılır).
        $this->inTenant($studio->tenant->id, fn () => $service->switchTemplate($brief->fresh(), $quick));

        $back = $this->inTenant($studio->tenant->id, fn () => $service->valuesByKey($brief->fresh()));
        $this->assertSame('Bakı, Nizami 1', $back['object_address'] ?? null, 'Geri keçid cavabı itirməməlidir.');
        $this->assertSame($quick->id, $brief->fresh()->brief_template_id);
    }

    /**
     * Şablon dəyişdikdən sonra faiz YENİDƏN hesablanmalıdır: Quick-də 12 sualdan
     * 2-si 17% verir, Premium-da eyni 2 cavab onlarla sualdan biridir.
     */
    public function test_switching_the_template_recalculates_the_progress(): void
    {
        $studio = $this->seededStudio('sw-prog');
        $quick = BriefTemplate::where('key', 'quick')->firstOrFail();
        $premium = BriefTemplate::where('key', 'residential')->firstOrFail();

        $brief = $this->inTenant($studio->tenant->id, function () use ($studio, $quick) {
            $brief = app(BriefService::class)->forProject($studio->project);
            $brief->forceFill(['brief_template_id' => $quick->id])->save();

            return $brief->fresh();
        });

        $this->answerOnTemplate($studio, $brief, $quick, [
            'object_address' => 'Bakı',
            'total_area_sqm' => '120',
        ]);

        $service = app(BriefService::class);
        $this->inTenant($studio->tenant->id, fn () => $service->recalculateProgress($brief->fresh()));
        $quickProgress = (int) $brief->fresh()->progress;

        $this->inTenant($studio->tenant->id, fn () => $service->switchTemplate($brief->fresh(), $premium));
        $premiumProgress = (int) $brief->fresh()->progress;

        $this->assertGreaterThan(0, $quickProgress);
        $this->assertLessThan(
            $quickProgress,
            $premiumProgress,
            'Premium şablonda sual sayı çox olduğu üçün faiz azalmalıdır.',
        );
    }

    /**
     * Kilid/kilid açma: göndəriş müştərini bağlayır, `reopen()` isə cavabları
     * itirmədən yenidən açır və faizi bərpa edir.
     */
    public function test_submit_locks_the_brief_and_reopen_unlocks_it_without_losing_answers(): void
    {
        $studio = $this->seededStudio('lock');
        $brief = $this->answer($studio, ['object_address' => 'Bakı, Nizami 1']);

        $this->inTenant($studio->tenant->id, function () use ($studio, $brief) {
            $service = app(BriefService::class);
            $service->submit($brief);

            $this->assertTrue($brief->fresh()->isLocked());
            $this->assertSame(100, (int) $brief->fresh()->progress);

            $service->reopen($brief->fresh(), $studio->staff['designer']);
        });

        $reopened = $brief->fresh();

        $this->assertFalse($reopened->isLocked());
        $this->assertSame(BriefStatus::InProgress->value, $reopened->status);
        $this->assertNull($reopened->submitted_at);
        $this->assertLessThan(100, (int) $reopened->progress, 'Yenidən açılan brifdə faiz bərpa olunmalıdır.');
        $this->assertSame(1, $reopened->answers()->count(), 'Cavablar silinməməlidir.');

        // Kilidli brifi ikinci dəfə göndərmək heç nə etməməlidir (erkən qayıdış).
        $this->inTenant($studio->tenant->id, function () use ($brief) {
            $service = app(BriefService::class);
            $service->submit($brief->fresh());
            $versions = $brief->fresh()->versions()->count();
            $service->submit($brief->fresh());

            $this->assertSame($versions, $brief->fresh()->versions()->count(), 'Təkrar göndəriş yeni versiya yaratmamalıdır.');
        });
    }

    /** Texniki tapşırıq sayğacı BRİFİN özünə bağlıdır — iki layihə bir-birinə qarışmır. */
    public function test_the_technical_spec_version_counts_per_brief(): void
    {
        $studio = $this->seededStudio('tzver');

        $first = $this->answer($studio, ['object_address' => 'Bakı 1']);
        $second = $this->inTenant(
            $studio->tenant->id,
            fn () => app(BriefService::class)->forProject($studio->otherProject),
        );

        $this->inTenant($studio->tenant->id, function () use ($first, $second) {
            $service = app(BriefService::class);

            $this->assertStringContainsString('v1', $service->buildTechnicalSpec($first)->title);
            $this->assertStringContainsString('v2', $service->buildTechnicalSpec($first)->title);
            // İkinci brif öz sayğacı ilə başlamalıdır.
            $this->assertStringContainsString('v1', $service->buildTechnicalSpec($second)->title);
        });

        $this->assertSame(2, (int) $first->fresh()->technical_spec_version);
        $this->assertSame(1, (int) $second->fresh()->technical_spec_version);
    }

    /** Risk aşkarlanan cavab `answerPriorities()`-də riskin səviyyəsini almalıdır. */
    public function test_a_risk_flagged_answer_gets_the_risk_level_in_the_priority_list(): void
    {
        $studio = $this->seededStudio('prio');
        $brief = $this->answer($studio, ['has_measurement_plan' => 'no']);

        $row = $this->inTenant($studio->tenant->id, fn () => app(BriefService::class)
            ->answerPriorities($brief->fresh())
            ->first(fn (array $r) => $r['question']->key === 'has_measurement_plan'));

        $this->assertNotNull($row, 'Cavablanmış sual prioritet siyahısında olmalıdır.');
        $this->assertSame('missing', $row['priority'], 'R4 «missing» səviyyəsindədir.');
        $this->assertStringContainsString('R4', (string) $row['note']);
    }

    // ─────────────────────── 5. BriefRiskDetector ───────────────────────

    /**
     * Hər qayda YALNIZ öz məlumatında işə düşməlidir. Yalan-pozitiv dizayneri
     * saxta xəbərdarlıqla doldurur, yalan-neqativ isə real riski gizlədir —
     * ona görə hər qayda üçün həm işə düşən, həm susan hal yoxlanılır.
     */
    public function test_every_risk_rule_fires_on_its_own_data_and_stays_silent_otherwise(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $cases = [
            // [kod, işə düşən cavablar, susan cavablar]
            ['R1',
                ['design_area_sqm' => '100', 'project_budget_range' => ['min' => '5000', 'max' => '10000']],
                ['design_area_sqm' => '100', 'project_budget_range' => ['min' => '40000', 'max' => '90000']],
            ],
            ['R2',
                ['cooperation_scope' => 'turnkey', 'desired_completion_date' => now()->addDays(30)->toDateString()],
                ['cooperation_scope' => 'turnkey', 'desired_completion_date' => now()->addDays(400)->toDateString()],
            ],
            ['R4',
                ['has_measurement_plan' => 'no'],
                ['has_measurement_plan' => 'yes'],
            ],
            ['R5',
                ['curtains' => ['none'], 'blackout_zones' => 'yataq otağı'],
                ['curtains' => ['tulle'], 'blackout_zones' => 'yataq otağı'],
            ],
            ['R6',
                ['wall_materials' => ['designer', 'paint']],
                ['wall_materials' => ['designer']],
            ],
            ['R8',
                ['demolition_needed' => 'yes', 'cooperation_scope' => 'design_only'],
                ['demolition_needed' => 'yes', 'cooperation_scope' => 'turnkey'],
            ],
        ];

        foreach ($cases as $index => [$code, $firing, $silent]) {
            $fired = $this->riskCodes('risk-on-'.$index, $firing);
            $this->assertContains($code, $fired, "{$code} öz məlumatında işə düşməlidir. Alınan: ".implode(',', $fired));

            $quiet = $this->riskCodes('risk-off-'.$index, $silent);
            $this->assertNotContains($code, $quiet, "{$code} bu məlumatda susmalıdır. Alınan: ".implode(',', $quiet));
        }
    }

    /** Cavabsız brifdə heç bir risk olmamalıdır — boş anket xəbərdarlıq mənbəyi deyil. */
    public function test_an_empty_brief_raises_no_risks(): void
    {
        $this->assertSame([], $this->riskCodes('risk-empty', []));
    }

    /** R6 hər üç material blokunda ayrıca işə düşməlidir. */
    public function test_the_designer_choice_conflict_is_detected_in_every_material_block(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $codes = $this->riskCodes('risk-r6', [
            'wall_materials' => ['designer', 'paint'],
            'floor_materials' => ['designer', 'parquet'],
            'ceiling_materials' => ['designer', 'stretch'],
        ]);

        $this->assertSame(['R6', 'R6', 'R6'], array_values(array_filter($codes, fn ($c) => $c === 'R6')));
    }

    // ─────────────────────── 6. ŞƏRTİ MƏNTİQ (skip) ───────────────────────

    /**
     * Şərti ödənməyən sual NƏ faiz məxrəcinə, NƏ texniki tapşırığa düşməlidir.
     * Köhnə cavab bazada qalır (müştəri fikrini dəyişə bilər), amma «heyvanlar
     * yoxdur» deyən brifin texniki tapşırığında heyvan zonası tələbi olmamalıdır.
     */
    public function test_a_hidden_question_leaves_the_progress_denominator_and_the_technical_spec(): void
    {
        $studio = $this->seededStudio('skip');
        $service = app(BriefService::class);

        // «Ev heyvanı var» → şərti sual görünür və cavablanır.
        $brief = $this->answer($studio, [
            'has_pets' => '1',
            'pet_zone_needs' => 'Caynaq itiləyən lazımdır',
        ]);

        $shown = $this->inTenant($studio->tenant->id, fn () => $service->sectionMap($brief->fresh()))
            ->firstWhere(fn ($e) => $e['section']->key === 'about_you');

        $this->assertTrue(
            $shown['section']->questions->contains('key', 'pet_zone_needs'),
            'Şərt ödəniləndə sual bölmədədir.',
        );
        $withPets = $shown['question_count'];

        $html = $this->renderSpec($brief);
        $this->assertStringContainsString('Caynaq itiləyən lazımdır', $html);

        // Müştəri fikrini dəyişir: «heyvan yoxdur».
        $this->inTenant($studio->tenant->id, function () use ($brief) {
            $brief->answers()
                ->where('brief_question_id', $this->residentialQuestion('has_pets')->id)
                ->update(['value' => json_encode('0')]);
        });

        $hidden = $this->inTenant($studio->tenant->id, fn () => $service->sectionMap($brief->fresh()))
            ->firstWhere(fn ($e) => $e['section']->key === 'about_you');

        $this->assertSame(
            $withPets - 1,
            $hidden['question_count'],
            'Gizlənən sual faiz məxrəcindən çıxmalıdır.',
        );

        $this->assertStringNotContainsStringQuietly(
            'Caynaq itiləyən lazımdır',
            $this->renderSpec($brief->fresh()),
            'Gizlənən sualın köhnə cavabı texniki tapşırığa düşməməlidir.',
        );
    }

    // ─────────────────────── 7. SEEDER İDEMPOTENTLİYİ ───────────────────────

    /** Seeder-i ikinci dəfə işlətmək heç nə çoxaltmamalı, heç nə itirməməlidir. */
    public function test_running_the_bank_seeder_twice_duplicates_nothing_and_keeps_answers(): void
    {
        $studio = $this->seededStudio('seed2');
        $brief = $this->answer($studio, ['object_address' => 'Bakı, Nizami 1']);

        $before = [
            'templates' => BriefTemplate::count(),
            'sections' => BriefSection::count(),
            'questions' => BriefQuestion::count(),
            'options' => $this->residentialQuestion('color_combinations')->options,
        ];

        $this->seed(BriefQuestionBankSeeder::class);

        $this->assertSame($before['templates'], BriefTemplate::count(), 'Şablon sayı dəyişməməlidir.');
        $this->assertSame($before['sections'], BriefSection::count(), 'Bölmə sayı dəyişməməlidir.');
        $this->assertSame($before['questions'], BriefQuestion::count(), 'Sual sayı dəyişməməlidir.');
        $this->assertSame($before['options'], $this->residentialQuestion('color_combinations')->options);

        $this->assertSame(1, $brief->fresh()->answers()->count(), 'Cavab itməməlidir.');
        $this->assertSame(
            'Bakı, Nizami 1',
            $this->inTenant($studio->tenant->id, fn () => app(BriefService::class)->valuesByKey($brief->fresh()))['object_address'] ?? null,
        );

        // Bölmə açarları qlobal unikaldır — təkrar açar olmamalıdır.
        $this->assertSame([], BriefSection::pluck('key')->duplicates()->values()->all());
    }

    /**
     * Bankdan çıxarılan bölmə SİLİNMİR, deaktiv olunur — cavablar sualların
     * id-sinə bağlıdır, silinsə kaskad onları da aparardı.
     */
    public function test_a_section_dropped_from_the_bank_is_deactivated_not_deleted(): void
    {
        $studio = $this->seededStudio('stale');
        $brief = $this->answer($studio, ['object_address' => 'Bakı, Nizami 1']);

        $residential = BriefTemplate::where('key', 'residential')->firstOrFail();

        // Bankda olmayan bölmə əlavə edirik — növbəti seed onu deaktiv etməlidir.
        $stale = BriefSection::create([
            'brief_template_id' => $residential->id,
            'key' => 'stale_section_qa2',
            'name' => ['az' => 'Köhnə bölmə'],
            'position' => 99,
            'active' => true,
        ]);

        $staleQuestion = BriefQuestion::create([
            'brief_section_id' => $stale->id,
            'key' => 'stale_question_qa2',
            'label' => ['az' => 'Köhnə sual'],
            'type' => 'text',
            'options' => null,
            'is_required' => false,
            'allows_designer_choice' => false,
            'position' => 0,
            'active' => true,
        ]);

        $answer = $brief->answers()->create([
            'brief_question_id' => $staleQuestion->id,
            'value' => 'köhnə cavab',
            'answered_at' => now(),
        ]);

        $this->seed(BriefQuestionBankSeeder::class);

        $this->assertNotNull($stale->fresh(), 'Bölmə silinməməlidir.');
        $this->assertFalse((bool) $stale->fresh()->active, 'Bölmə deaktiv olmalıdır.');
        $this->assertNotNull($answer->fresh(), 'Deaktiv bölmənin cavabı qalmalıdır.');

        // Sehrbazda görünməməlidir.
        $keys = $this->inTenant($studio->tenant->id, fn () => app(BriefService::class)
            ->sectionMap($brief->fresh())
            ->map(fn ($e) => $e['section']->key));

        $this->assertFalse($keys->contains('stale_section_qa2'));
    }

    // ─────────────────────── 8. ÇOX-KİRAYƏÇİLİK ───────────────────────

    /**
     * Studiya A-nın brifi studiya B üçün NƏ sorğuda, NƏ panel ekranında
     * əlçatan olmamalıdır. Sual bankı qəsdən qlobaldır (git-dəki kataloq),
     * brif özü isə kirayəçiyə bağlıdır.
     */
    public function test_a_brief_of_another_studio_is_invisible_and_unreachable(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        Storage::fake('public');

        $a = StudioWorld::make('ten-a');
        $b = StudioWorld::make('ten-b');

        $briefA = $this->inTenant($a->tenant->id, fn () => app(BriefService::class)->forProject($a->project));

        // Sorğu qatı: B kontekstində A-nın brifi görünmür.
        $this->inTenant($b->tenant->id, function () use ($briefA) {
            $this->assertNull(Brief::find($briefA->id), 'Kirayəçi qlobal scope-u A-nın brifini gizlətməlidir.');
            $this->assertSame(0, Brief::whereKey($briefA->id)->count());
        });

        // Panel qatı: B-nin sahibkarı A-nın layihəsinin brif ekranını açmamalıdır.
        $this->actingAs($b->staff['owner']);
        Filament::setCurrentPanel('admin');
        app(TenantContext::class)->set($b->tenant->id);

        try {
            $this->get(ProjectResource::getUrl('brief-review', ['record' => $a->project->getKey()]))
                ->assertNotFound();
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    /** Öz studiyasının layihəsində brif ekranı açılmalıdır — yuxarıdaki bloklama həddindən artıq olmasın. */
    public function test_the_brief_review_screen_opens_for_the_studios_own_project(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        Storage::fake('public');

        $a = StudioWorld::make('ten-own');
        $this->inTenant($a->tenant->id, fn () => app(BriefService::class)->forProject($a->project));

        $this->actingAs($a->staff['owner']);
        Filament::setCurrentPanel('admin');
        app(TenantContext::class)->set($a->tenant->id);

        try {
            $this->get(ProjectResource::getUrl('brief-review', ['record' => $a->project->getKey()]))->assertOk();
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    // ─────────────────────── köməkçilər ───────────────────────

    /** Panelə daxil olmuş işçi — rol AccessMatrix açarıdır. */
    private function asStaff(string $role, string $email): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'is_active' => true,
        ]);

        AccessMatrix::flushCache();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        return $user;
    }

    private function seededStudio(string $slug): StudioWorld
    {
        Storage::fake('public');
        $this->seed(BriefQuestionBankSeeder::class);

        return StudioWorld::make($slug);
    }

    /** Açar quick + residential şablonlarında təkrarlanır — residential olanı. */
    private function residentialQuestion(string $key): BriefQuestion
    {
        return $this->questionOf('residential', $key);
    }

    private function questionOf(string $templateKey, string $key): BriefQuestion
    {
        return BriefQuestion::where('key', $key)
            ->whereIn('brief_section_id', BriefSection::where(
                'brief_template_id',
                BriefTemplate::where('key', $templateKey)->value('id'),
            )->select('id'))
            ->firstOrFail();
    }

    /** @param  array<string, mixed>  $byKey */
    private function answer(StudioWorld $studio, array $byKey): Brief
    {
        return $this->inTenant($studio->tenant->id, function () use ($studio, $byKey) {
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

    /** @param  array<string, mixed>  $byKey */
    private function answerOnTemplate(StudioWorld $studio, Brief $brief, BriefTemplate $template, array $byKey): void
    {
        $this->inTenant($studio->tenant->id, function () use ($brief, $template, $byKey) {
            foreach ($byKey as $key => $value) {
                $brief->answers()->create([
                    'brief_question_id' => $this->questionOf($template->key, $key)->id,
                    'brief_room_id' => null,
                    'value' => $value,
                    'answered_at' => now(),
                ]);
            }
        });
    }

    /**
     * Verilmiş cavablarla brif qurub aşkarlanan risk kodlarını qaytarır.
     *
     * @param  array<string, mixed>  $byKey
     * @return list<string>
     */
    private function riskCodes(string $slug, array $byKey): array
    {
        Storage::fake('public');

        if (BriefTemplate::count() === 0) {
            $this->seed(BriefQuestionBankSeeder::class);
        }

        $studio = StudioWorld::make($slug);
        $brief = $this->answer($studio, $byKey);

        return $this->inTenant(
            $studio->tenant->id,
            fn () => array_column(app(BriefRiskDetector::class)->detect($brief->fresh()), 'code'),
        );
    }

    /** Texniki tapşırığın HTML-i — PDF-ə getməzdən əvvəlki məzmun. */
    private function renderSpec(Brief $brief): string
    {
        return $this->inTenant($brief->project->tenant_id, function () use ($brief) {
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

    /**
     * Repeater sətirləri forma vəziyyətində UUID ilə açarlanır — testin real
     * brauzer davranışını təqlid etməsi üçün açar formadan oxunur.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rows(Testable $form, string $path): array
    {
        $rows = data_get($form->get('data'), $path);

        return is_array($rows) ? $rows : [];
    }

    private function firstRowKey(Testable $form, string $path): string
    {
        $rows = $this->rows($form, $path);

        $this->assertNotEmpty($rows, "Formada «{$path}» sətirləri yoxdur.");

        return (string) array_key_first($rows);
    }

    /**
     * Boş `image_url` / `images` açarlarını atır: bank onları `null` kimi yazır,
     * panel isə açarı ümumiyyətlə saxlamır — məzmun fərqi yoxdur.
     */
    private function normalise(mixed $options): mixed
    {
        if (! is_array($options)) {
            return $options;
        }

        return array_map(function ($option) {
            if (! is_array($option)) {
                return $option;
            }

            foreach (['image_url', 'images'] as $field) {
                if (array_key_exists($field, $option) && blank($option[$field])) {
                    unset($option[$field]);
                }
            }

            return isset($option['items']) ? ['items' => $this->normalise($option['items'])] + $option : $option;
        }, isset($options['items']) ? ['items' => $this->normalise($options['items'])] + $options : $options);
    }

    /** Fərqi oxunaqlı göstərmək üçün `options` quruluşunun qısa təsviri. */
    private function shape(mixed $options): string
    {
        if (! is_array($options)) {
            return get_debug_type($options);
        }

        if (array_is_list($options)) {
            return 'list('.count($options).')';
        }

        return 'config{'.implode(',', array_keys($options)).'}'
            .(isset($options['items']) && is_array($options['items']) ? ' items('.count($options['items']).')' : '');
    }

    private function inTenant(int $tenantId, callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($tenantId, $callback);
    }
}
