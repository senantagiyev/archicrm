<?php

namespace Tests\Feature;

use App\Enums\BriefStatus;
use App\Filament\Resources\BriefQuestionResource;
use App\Filament\Resources\BriefTemplateResource;
use App\Filament\Resources\BriefTemplateResource\Pages\CreateBriefTemplate;
use App\Filament\Resources\BriefTemplateResource\Pages\EditBriefTemplate;
use App\Filament\Resources\BriefTemplateResource\Pages\ListBriefTemplates;
use App\Filament\Resources\BriefTemplateResource\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\BriefAnswersRelationManager;
use App\Models\Brief;
use App\Models\BriefQuestion;
use App\Models\BriefTemplate;
use App\Models\Project;
use App\Models\User;
use App\Notifications\BriefPresented;
use App\Services\Brief\BriefBuilderService;
use App\Services\Brief\BriefService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\BriefQuestionBankSeeder;
use Database\Seeders\TranslationSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Brif konstruktoru — studiya öz brifini qurur, müştəriyə göndərir, müştəri
 * doldurur, studiya cavabları oxuyur.
 *
 * Üç şey ucdan-uca yoxlanılır:
 *  1. KONSTRUKTOR: fərdi brif yaradılır, hər tipdən sual əlavə olunur,
 *     variantlar (şəkilli də) renderin gözlədiyi formada saxlanılır, redaktə
 *     variant açarlarını dəyişmir, sistem şablonu toxunulmaz qalır, başqa
 *     studiya heç nə görmür.
 *  2. TƏQDİMAT: göndərilməmiş brif müştəri üçün MÖVCUD DEYİL (tab yox, kart
 *     yox, bölmə 404); göndəriləndən sonra açılır, bildiriş gedir.
 *  3. CAVABLAR: müştəri hər tipə cavab verir, yad variant rədd olunur, razılıq
 *     sualı olmayan brif göndərilə bilir, admin cavabları etiketlə görür.
 */
class BriefBuilderTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // `Storage::fake('public')` paylaşılan kökü silir; paralel test prosesləri
        // bir-birinin şəklini ortadan götürür — bax DocsApprovalsTest-dəki qeyd.
        $this->diskRoot = storage_path('framework/testing/disks/builder-'.getmypid());
        File::deleteDirectory($this->diskRoot);
        File::ensureDirectoryExists($this->diskRoot);
        config(['filesystems.disks.public' => [
            'driver' => 'local', 'root' => $this->diskRoot, 'url' => '/storage', 'visibility' => 'public', 'throw' => false,
        ]]);
        Storage::forgetDisk('public');

        $this->seed(TranslationSeeder::class);
        $this->seed(BriefQuestionBankSeeder::class);

        $this->studio = StudioWorld::make('builder');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    // ═══════════════════════ 1. KONSTRUKTOR ═══════════════════════

    public function test_a_studio_creates_a_custom_brief_with_a_name_and_gets_its_own_section(): void
    {
        $owner = $this->asStaff('owner');

        Livewire::test(CreateBriefTemplate::class)
            ->fillForm(['title' => 'Sənan bəy üçün hazırlanmış brif', 'summary' => 'Mənzil, 3 otaq'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $template = BriefTemplate::where('tenant_id', $this->studio->tenant->id)->firstOrFail();

        $this->assertSame('Sənan bəy üçün hazırlanmış brif', $template->getTranslation('name', 'az'));
        $this->assertSame('Mənzil, 3 otaq', $template->getTranslation('description', 'az'));
        $this->assertSame(BriefTemplate::LEVEL_CUSTOM, $template->level);
        $this->assertSame($owner->id, $template->created_by_user_id);
        $this->assertTrue($template->isCustom());
        $this->assertStringStartsWith('custom-', $template->key);

        // Yeganə bölmə — brifin adı ilə; portal onu bir kart kimi göstərir.
        $section = $template->primarySection;
        $this->assertNotNull($section);
        $this->assertSame('Sənan bəy üçün hazırlanmış brif', $section->getTranslation('name', 'az'));
        $this->assertStringStartsWith('custom-', $section->key);
        $this->assertNull($section->room_type);
    }

    public function test_every_builder_question_type_is_stored_in_the_shape_the_portal_renders(): void
    {
        $this->asStaff('owner');
        $template = $this->customTemplate();

        $rm = $this->questionsManager($template);

        $rm->callTableAction('create', data: [
            'type' => 'text', 'label' => 'Obyektin ünvanı', 'help' => 'Şəhər, küçə, ev', 'is_required' => true,
        ])->assertHasNoTableActionErrors();

        $rm->callTableAction('create', data: ['type' => 'textarea', 'label' => 'Ən vacib istəyiniz'])
            ->assertHasNoTableActionErrors();

        $rm->callTableAction('create', data: [
            'type' => 'select', 'label' => 'Obyektin tipi',
            'option_list' => [['label' => 'Mənzil'], ['label' => 'Fərdi ev'], ['label' => 'Ofis']],
        ])->assertHasNoTableActionErrors();

        $rm->callTableAction('create', data: [
            'type' => 'multiselect', 'label' => 'Hansı otaqlar var?',
            'option_list' => [['label' => 'Qonaq otağı'], ['label' => 'Mətbəx'], ['label' => 'Yataq otağı'], ['label' => 'Kabinet']],
        ])->assertHasNoTableActionErrors();

        $rm->callTableAction('create', data: [
            'type' => 'image_select', 'label' => 'Lüstr tərzi',
            'option_list' => [
                ['label' => 'Klassik', 'image_url' => $this->img('klassik.jpg', 640, 480)],
                ['label' => 'Modern', 'image_url' => $this->img('modern.jpg', 640, 480)],
                ['label' => 'Minimal', 'image_url' => $this->img('minimal.jpg', 640, 480)],
            ],
        ])->assertHasNoTableActionErrors();

        $rm->callTableAction('create', data: [
            'type' => 'image_multiselect', 'label' => 'Bəyəndiyiniz üslublar',
            'option_list' => [
                ['label' => 'Skandinav', 'image_url' => $this->img('scandi.jpg', 640, 480)],
                ['label' => 'Loft', 'image_url' => $this->img('loft.jpg', 640, 480)],
            ],
        ])->assertHasNoTableActionErrors();

        foreach ([
            ['boolean', 'Ev heyvanı var?'],
            ['number', 'Ümumi sahə, m²'],
            ['date', 'İstənilən bitmə tarixi'],
            ['file', 'Obmer planı'],
        ] as [$type, $label]) {
            $rm->callTableAction('create', data: ['type' => $type, 'label' => $label])->assertHasNoTableActionErrors();
        }

        $questions = $template->fresh()->questions()->get()->keyBy(fn (BriefQuestion $q) => $q->getTranslation('label', 'az'));

        $this->assertCount(10, $questions);
        $this->assertTrue($questions->every(fn (BriefQuestion $q) => $q->brief_section_id === $template->primarySection->id));
        $this->assertTrue($questions->every(fn (BriefQuestion $q) => str_starts_with($q->key, 'q-')));
        $this->assertSame(10, $questions->pluck('key')->unique()->count(), 'Açarlar unikal olmalıdır.');

        // Mətn sualı: options yoxdur, məcburidir, izah saxlanılıb.
        $text = $questions->get('Obyektin ünvanı');
        $this->assertNull($text->options);
        $this->assertTrue($text->is_required);
        $this->assertSame('Şəhər, küçə, ev', $text->getTranslation('help', 'az'));
        $this->assertFalse($text->allows_designer_choice, 'Fərdi sualda defolt: dizaynerə həvalə YOXDUR.');

        // Tək seçim: renderin gözlədiyi `{value, label: {az}}` forması.
        $select = $questions->get('Obyektin tipi');
        $this->assertCount(3, $select->options);
        $this->assertSame(['Mənzil', 'Fərdi ev', 'Ofis'], array_column(array_column($select->options, 'label'), 'az'));
        foreach ($select->options as $option) {
            $this->assertStringStartsWith('opt-', $option['value']);
            $this->assertArrayNotHasKey('image_url', $option);
        }
        $this->assertSame(3, collect($select->options)->pluck('value')->unique()->count());

        // Şəkilli seçim: hər kartın şəkli diskdədir və `image_url` yol kimi saxlanılır.
        $images = $questions->get('Lüstr tərzi');
        $this->assertCount(3, $images->options);
        foreach ($images->options as $option) {
            $this->assertIsString($option['image_url'] ?? null, 'Şəkil yolu sətir olmalıdır (massiv deyil).');
            $this->assertStringStartsWith('brief/custom/', $option['image_url']);
            Storage::disk('public')->assertExists($option['image_url']);
        }
        $this->assertSame('Klassik', $images->options[0]['label']['az']);

        // Sıra: əlavə olunduğu ardıcıllıqla.
        $this->assertSame(
            ['Obyektin ünvanı', 'Ən vacib istəyiniz', 'Obyektin tipi', 'Hansı otaqlar var?', 'Lüstr tərzi', 'Bəyəndiyiniz üslublar', 'Ev heyvanı var?', 'Ümumi sahə, m²', 'İstənilən bitmə tarixi', 'Obmer planı'],
            $template->fresh()->questions()->get()->map(fn ($q) => $q->getTranslation('label', 'az'))->all(),
        );
    }

    /** Variant açarı redaktədə DƏYİŞMİR — müştərinin köhnə cavabı tanınmaz qalmasın. */
    public function test_editing_a_question_keeps_option_values_stable_and_can_add_or_drop_options(): void
    {
        $this->asStaff('owner');
        $template = $this->customTemplate();
        $rm = $this->questionsManager($template);

        $rm->callTableAction('create', data: [
            'type' => 'select', 'label' => 'Obyektin tipi',
            'option_list' => [['label' => 'Mənzil'], ['label' => 'Fərdi ev']],
        ]);

        $question = $template->fresh()->questions()->firstOrFail();
        [$apartment, $house] = array_column($question->options, 'value');

        // Etiketlər düzəldilir, bir variant əlavə olunur, sıra dəyişir.
        $rm->callTableAction('edit', $question, data: [
            'label' => 'Obyektin növü',
            'is_required' => true,
            'option_list' => [
                ['value' => $house, 'label' => 'Həyət evi'],
                ['value' => $apartment, 'label' => 'Mənzil (yeni tikili)'],
                ['label' => 'Ofis'],
            ],
        ])->assertHasNoTableActionErrors();

        $question->refresh();

        $this->assertSame('Obyektin növü', $question->getTranslation('label', 'az'));
        $this->assertTrue($question->is_required);
        $this->assertCount(3, $question->options);
        $this->assertSame($house, $question->options[0]['value'], 'Mövcud variantın açarı qorunmalıdır.');
        $this->assertSame('Həyət evi', $question->options[0]['label']['az']);
        $this->assertSame($apartment, $question->options[1]['value']);
        $this->assertStringStartsWith('opt-', $question->options[2]['value']);

        // Bir variant silinir.
        $rm->callTableAction('edit', $question, data: [
            'option_list' => [
                ['value' => $house, 'label' => 'Həyət evi'],
                ['value' => $apartment, 'label' => 'Mənzil'],
            ],
        ])->assertHasNoTableActionErrors();

        $this->assertSame([$house, $apartment], array_column($question->fresh()->options, 'value'));
    }

    public function test_the_form_refuses_a_choice_question_with_fewer_than_two_options_and_an_empty_label(): void
    {
        $this->asStaff('owner');
        $template = $this->customTemplate();
        $rm = $this->questionsManager($template);

        $rm->callTableAction('create', data: ['type' => 'select', 'label' => 'Tək variant', 'option_list' => [['label' => 'Yalnız bu']]])
            ->assertHasTableActionErrors(['option_list']);

        // Uğursuz validasiya modalı açıq saxlayır — ikinci cəhd təzə komponentdə.
        $this->questionsManager($template)
            ->callTableAction('create', data: ['type' => 'text', 'label' => ''])
            ->assertHasTableActionErrors(['label']);

        $this->assertSame(0, $template->fresh()->questions()->count());
    }

    public function test_a_question_can_be_deleted_and_the_template_only_while_no_brief_uses_it(): void
    {
        $owner = $this->asStaff('owner');
        $template = $this->customTemplate();
        $rm = $this->questionsManager($template);

        $rm->callTableAction('create', data: ['type' => 'text', 'label' => 'Silinəcək']);
        $question = $template->fresh()->questions()->firstOrFail();

        $rm->callTableAction('delete', $question);
        $this->assertNull(BriefQuestion::find($question->id));

        $this->assertTrue($owner->can('delete', $template), 'İstifadə olunmayan fərdi brif silinə bilməlidir.');

        app(BriefService::class)->present($this->studio->project, $template, $owner);

        AccessMatrix::flushCache();
        $this->assertFalse($owner->fresh()->can('delete', $template->fresh()), 'Göndərilmiş brif silinə bilməz — cavablar sualsız qalar.');
    }

    /** Sistem şablonu paneldən dəyişdirilmir; onun sual cədvəli ümumiyyətlə açılmır. */
    public function test_system_templates_are_visible_but_never_editable(): void
    {
        $owner = $this->asStaff('owner');
        $system = BriefTemplate::where('key', 'residential')->firstOrFail();

        $this->assertTrue($owner->can('view', $system));
        $this->assertFalse($owner->can('update', $system));
        $this->assertFalse($owner->can('delete', $system));
        $this->assertFalse(QuestionsRelationManager::canViewForRecord($system, EditBriefTemplate::class));

        $this->get(BriefTemplateResource::getUrl('edit', ['record' => $system]))->assertForbidden();

        // Siyahıda görünür — göndərmək üçün.
        Livewire::test(ListBriefTemplates::class)
            ->assertCanSeeTableRecords([$system])
            ->assertTableActionVisible('sendToProject', $system)
            ->assertTableActionHidden('edit', $system)
            // Yerində redaktə yox — «Redaktə et» studiya nüsxəsi yaradır (BriefTemplateForkTest).
            ->assertTableActionVisible('customize', $system);

        // Sistem bankının sualı isə konstruktor servisi ilə də dəyişdirilmir.
        $bankQuestion = $system->questions()->firstOrFail();
        $this->expectException(\InvalidArgumentException::class);
        app(BriefBuilderService::class)->updateQuestion($bankQuestion, ['label' => 'Pozuntu']);
    }

    /** Studiya B studiya A-nın fərdi brifini nə görür, nə redaktə edir, nə göndərir. */
    public function test_a_custom_brief_is_invisible_to_another_studio(): void
    {
        $this->asStaff('owner');
        $template = $this->customTemplate('A studiyasının brifi');
        $this->questionsManager($template)->callTableAction('create', data: ['type' => 'text', 'label' => 'A sualı']);

        $other = StudioWorld::make('builder-b');
        $stranger = $this->asStaff('owner', $other);

        $this->assertFalse($stranger->can('view', $template));
        $this->assertFalse($stranger->can('update', $template));

        Livewire::test(ListBriefTemplates::class)
            ->assertCanNotSeeTableRecords([$template])
            ->assertCanSeeTableRecords([BriefTemplate::where('key', 'residential')->firstOrFail()]);

        $status = $this->get(BriefTemplateResource::getUrl('edit', ['record' => $template]))->status();
        $this->assertContains($status, [403, 404], 'Yad studiya fərdi brifin redaktə səhifəsini açdı.');

        // Layihənin «göndər» siyahısında da yoxdur.
        $html = Livewire::test(BriefAnswersRelationManager::class, [
            'ownerRecord' => $other->project, 'pageClass' => EditProject::class,
        ])->mountTableAction('briefTemplate')->html();

        $this->assertStringNotContainsString(
            'A studiyasının brifi',
            $html,
            'Yad studiyanın göndərmə siyahısında A-nın brifi göründü. stranger tenant='.auth()->user()?->tenant_id
                .' A tenant='.$template->tenant_id.' | '.substr($html, max(0, (int) mb_strpos($html, 'A studiyasının brifi') - 300), 700),
        );

        // Payload ilə birbaşa göndərmək də alınmır.
        try {
            Livewire::test(BriefAnswersRelationManager::class, ['ownerRecord' => $other->project, 'pageClass' => EditProject::class])
                ->callTableAction('briefTemplate', data: ['brief_template_id' => $template->id]);
        } catch (\Throwable) {
            // 404 / validasiya — hər ikisi qəbulolunandır.
        }

        $this->assertNull(Brief::withoutGlobalScopes()->where('project_id', $other->project->id)->whereNotNull('presented_at')->first());

        // Yad sual «Brif şəkilləri» ekranında da görünmür.
        $this->assertNull(BriefQuestionResource::getEloquentQuery()->whereHas('section', fn ($q) => $q->where('brief_template_id', $template->id))->first());
    }

    /** Rollar: Brif = Tam yaradır, Brif = Baxış yalnız baxır, Brif = Yoxdur heç girmir. */
    public function test_only_roles_with_full_brief_access_can_build(): void
    {
        $designer = $this->asStaff('designer');
        $this->assertTrue($designer->can('create', BriefTemplate::class));
        $this->get(BriefTemplateResource::getUrl('create'))->assertOk();

        $viewer = $this->asStaff('visualizer');
        $this->assertFalse($viewer->can('create', BriefTemplate::class));
        $this->get(BriefTemplateResource::getUrl('create'))->assertForbidden();
        $this->get(BriefTemplateResource::getUrl('index'))->assertOk();

        $none = $this->asStaff('accountant');
        $this->get(BriefTemplateResource::getUrl('index'))->assertForbidden();
        $this->assertFalse($none->can('viewAny', BriefTemplate::class));
    }

    // ═══════════════════════ 2. TƏQDİMAT ═══════════════════════

    public function test_before_presentation_the_client_sees_no_brief_anywhere(): void
    {
        // Studiya tərəfdə qaralama var (menecer «Brif» tabını açmışdı) — amma
        // göndərilməyib.
        $this->inTenant(fn () => app(BriefService::class)->forProject($this->studio->project));

        $this->assertFalse($this->studio->project->fresh()->brief->isPresented());

        $client = $this->actingAs($this->studio->portalUser, 'customer');
        $project = $this->studio->project;

        // Layihə səhifəsi: nə brif tabı, nə brif kartı.
        $client->get(route('portal.projects.show', $project))
            ->assertOk()
            ->assertDontSee(route('portal.brief', $project), false);

        // Birbaşa URL: izahlı boş səhifə, 404 deyil.
        $client->get(route('portal.brief', $project))
            ->assertOk()
            ->assertSee(t('portal.brief_not_presented_title'))
            ->assertDontSee(t('portal.brief_total_progress'));

        // Bölmə, avtosaxlama, göndərmə — mövcud deyil.
        $section = BriefTemplate::default()->sections()->firstOrFail();
        $client->get(route('portal.brief.section', [$project, $section]))->assertNotFound();
        $client->patchJson(route('portal.brief.autosave', [$project, $section]), [
            'question_id' => $section->questions()->firstOrFail()->id, 'value' => 'x', 'delegated' => false,
        ])->assertNotFound();
        $client->post(route('portal.brief.send', $project))->assertNotFound();
        $client->get(route('portal.brief.summary', $project))->assertNotFound();
    }

    public function test_presenting_from_the_project_opens_the_brief_and_notifies_the_client_once(): void
    {
        Notification::fake();

        $owner = $this->asStaff('owner');
        $template = $this->customTemplate('Sənan bəy üçün brif');
        $this->questionsManager($template)->callTableAction('create', data: ['type' => 'text', 'label' => 'Ünvan']);

        Livewire::test(BriefAnswersRelationManager::class, ['ownerRecord' => $this->studio->project, 'pageClass' => EditProject::class])
            ->assertTableActionVisible('briefTemplate')
            ->callTableAction('briefTemplate', data: ['brief_template_id' => $template->id])
            ->assertHasNoTableActionErrors();

        $brief = $this->studio->project->fresh()->brief;

        $this->assertTrue($brief->isPresented());
        $this->assertSame($template->id, $brief->brief_template_id);
        $this->assertSame(BriefStatus::Sent, $brief->statusEnum());

        Notification::assertSentTo($this->studio->portalUser, BriefPresented::class, function (BriefPresented $n) use ($brief) {
            $mail = $n->toMail($this->studio->portalUser);

            return $n->brief->is($brief)
                && str_contains($mail->subject, $this->studio->project->name)
                && in_array('mail', $n->via($this->studio->portalUser), true)
                && in_array('database', $n->via($this->studio->portalUser), true);
        });
        Notification::assertSentToTimes($this->studio->portalUser, BriefPresented::class, 1);
        Notification::assertNotSentTo($this->studio->secondPortalUser, BriefPresented::class);

        // Portalda artıq görünür: tab, kart, bölmə kartı brifin adı ilə.
        $client = $this->actingAs($this->studio->portalUser, 'customer');
        $client->get(route('portal.projects.show', $this->studio->project))
            ->assertOk()
            ->assertSee(route('portal.brief', $this->studio->project), false);
        $client->get(route('portal.brief', $this->studio->project))
            ->assertOk()
            ->assertSee('Sənan bəy üçün brif')
            ->assertSee(t('portal.brief_total_progress'));

        // Şablonu dəyişmək təqdimat faktını saxlayır və İKİNCİ bildiriş göndərmir.
        $presentedAt = $brief->presented_at;
        $quick = BriefTemplate::where('key', 'quick')->firstOrFail();

        // Yuxarıda müştəri kimi gəzdik — panel əməliyyatı üçün yenidən işçi oluruq.
        $this->asStaff('owner');

        Livewire::test(BriefAnswersRelationManager::class, ['ownerRecord' => $this->studio->project, 'pageClass' => EditProject::class])
            ->callTableAction('briefTemplate', data: ['brief_template_id' => $quick->id])
            ->assertHasNoTableActionErrors();

        $brief->refresh();
        $this->assertSame($quick->id, $brief->brief_template_id);
        $this->assertTrue($presentedAt->equalTo($brief->presented_at));
        Notification::assertSentToTimes($this->studio->portalUser, BriefPresented::class, 1);
    }

    public function test_presenting_from_the_brief_list_sends_it_to_the_chosen_project(): void
    {
        Notification::fake();

        $this->asStaff('owner');
        $template = $this->customTemplate('Siyahıdan göndərilən');
        $this->questionsManager($template)->callTableAction('create', data: ['type' => 'text', 'label' => 'Ünvan']);

        Livewire::test(ListBriefTemplates::class)
            ->callTableAction('sendToProject', $template, data: ['project_id' => $this->studio->otherProject->id])
            ->assertHasNoTableActionErrors();

        $brief = $this->studio->otherProject->fresh()->brief;
        $this->assertTrue($brief->isPresented());
        $this->assertSame($template->id, $brief->brief_template_id);
        Notification::assertSentTo($this->studio->secondPortalUser, BriefPresented::class);
        Notification::assertNotSentTo($this->studio->portalUser, BriefPresented::class);
    }

    /** «Yalnız öz layihələri» rolu üzvü olmadığı layihəyə brif göndərə bilməz. */
    public function test_an_own_projects_only_role_cannot_send_a_brief_to_a_foreign_project(): void
    {
        $this->asStaff('designer'); // layihə üzvüdür, otherProject-də deyil
        $template = $this->customTemplate();

        Livewire::test(ListBriefTemplates::class)
            ->callTableAction('sendToProject', $template, data: ['project_id' => $this->studio->otherProject->id])
            ->assertHasTableActionErrors(['project_id']);

        $this->assertNull(Brief::where('project_id', $this->studio->otherProject->id)->whereNotNull('presented_at')->first());
    }

    /** Təqdim edilmiş brif cavabsız qalanda «göndərilib»də qalır, qaralamaya düşmür. */
    public function test_progress_recalculation_never_unpresents_a_brief(): void
    {
        $owner = $this->asStaff('owner');
        $template = $this->customTemplate();
        $this->questionsManager($template)->callTableAction('create', data: ['type' => 'text', 'label' => 'Ünvan']);

        $brief = app(BriefService::class)->present($this->studio->project, $template, $owner);

        app(BriefService::class)->recalculateProgress($brief);
        $brief->refresh();

        $this->assertSame(BriefStatus::Sent, $brief->statusEnum());
        $this->assertTrue($brief->isPresented());
        $this->assertNotNull(app(BriefService::class)->presentedFor($this->studio->project));
    }

    // ═══════════════════════ 3. CAVABLAR ═══════════════════════

    public function test_the_client_answers_every_type_and_the_studio_reads_them_back_with_labels(): void
    {
        $owner = $this->asStaff('owner');
        $template = $this->customTemplate('Sənan bəy üçün brif');
        $rm = $this->questionsManager($template);

        $rm->callTableAction('create', data: ['type' => 'text', 'label' => 'Obyektin ünvanı', 'is_required' => true]);
        $rm->callTableAction('create', data: ['type' => 'textarea', 'label' => 'Ən vacib istəyiniz']);
        $rm->callTableAction('create', data: ['type' => 'select', 'label' => 'Obyektin tipi', 'option_list' => [['label' => 'Mənzil'], ['label' => 'Fərdi ev']]]);
        $rm->callTableAction('create', data: ['type' => 'multiselect', 'label' => 'Otaqlar', 'option_list' => [['label' => 'Qonaq otağı'], ['label' => 'Mətbəx'], ['label' => 'Kabinet']]]);
        $rm->callTableAction('create', data: ['type' => 'image_select', 'label' => 'Lüstr tərzi', 'option_list' => [
            ['label' => 'Klassik', 'image_url' => $this->img('k.jpg', 400, 300)],
            ['label' => 'Modern', 'image_url' => $this->img('m.jpg', 400, 300)],
        ]]);
        $rm->callTableAction('create', data: ['type' => 'image_multiselect', 'label' => 'Üslublar', 'option_list' => [
            ['label' => 'Skandinav', 'image_url' => $this->img('s.jpg', 400, 300)],
            ['label' => 'Loft', 'image_url' => $this->img('l.jpg', 400, 300)],
            ['label' => 'Eko', 'image_url' => $this->img('e.jpg', 400, 300)],
        ]]);
        $rm->callTableAction('create', data: ['type' => 'boolean', 'label' => 'Ev heyvanı var?']);
        $rm->callTableAction('create', data: ['type' => 'number', 'label' => 'Sahə, m²']);
        $rm->callTableAction('create', data: ['type' => 'date', 'label' => 'Bitmə tarixi']);

        app(BriefService::class)->present($this->studio->project, $template, $owner);

        $q = $template->fresh()->questions()->get()->keyBy(fn (BriefQuestion $x) => $x->getTranslation('label', 'az'));
        $opt = fn (string $label, int $i) => $q->get($label)->options[$i]['value'];
        $project = $this->studio->project;
        $section = $template->primarySection;

        // Bölmə səhifəsi: hər sual, hər variant etiketi, şəkil yolları.
        $page = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$project, $section]));

        $page->assertOk();
        foreach ($q->keys() as $label) {
            $page->assertSee($label);
        }
        $page->assertSee('Klassik')->assertSee('Skandinav')->assertSee('Fərdi ev')->assertSee('Kabinet');
        $page->assertSee($q->get('Lüstr tərzi')->options[0]['image_url'], false);
        $page->assertSee('data-type="image_select"', false);

        // Cavablar — portalın real endpointi ilə.
        $answers = [
            'Obyektin ünvanı' => 'Bakı, Nizami küç. 12',
            'Ən vacib istəyiniz' => "İş masası pəncərə önündə.\nÇoxlu saxlama yeri.",
            'Obyektin tipi' => $opt('Obyektin tipi', 1),
            'Otaqlar' => [$opt('Otaqlar', 0), $opt('Otaqlar', 2)],
            'Lüstr tərzi' => $opt('Lüstr tərzi', 1),
            'Üslublar' => [$opt('Üslublar', 0), $opt('Üslublar', 2)],
            'Ev heyvanı var?' => '1',
            'Sahə, m²' => '85',
            'Bitmə tarixi' => '2027-03-01',
        ];

        foreach ($answers as $label => $value) {
            $this->save($project, $section, $q->get($label), $value)->assertOk();
        }

        // Yad variant — 422, saxlanmır.
        $this->save($project, $section, $q->get('Obyektin tipi'), 'uydurma')->assertStatus(422);
        $this->save($project, $section, $q->get('Lüstr tərzi'), 'yad-kart')->assertStatus(422);
        $this->save($project, $section, $q->get('Otaqlar'), [$opt('Otaqlar', 0), 'yad'])->assertStatus(422);

        $brief = $project->fresh()->brief;
        $values = app(BriefService::class)->valuesByKey($brief);

        $this->assertSame('Bakı, Nizami küç. 12', $values[$q->get('Obyektin ünvanı')->key]);
        $this->assertSame($opt('Obyektin tipi', 1), $values[$q->get('Obyektin tipi')->key]);
        $this->assertEqualsCanonicalizing([$opt('Otaqlar', 0), $opt('Otaqlar', 2)], $values[$q->get('Otaqlar')->key]);
        $this->assertSame($opt('Lüstr tərzi', 1), $values[$q->get('Lüstr tərzi')->key]);
        $this->assertSame('85', (string) $values[$q->get('Sahə, m²')->key]);

        // Seçilmiş kart səhifədə seçilmiş kimi qayıdır.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$project, $section]))
            ->assertOk()
            ->assertSee('data-selected', false);

        // Razılıq sualı yoxdur — brif göndərilə BİLİR.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.submit', [$project, $section]))
            ->assertSessionHasNoErrors();

        $brief->refresh();
        $this->assertTrue($brief->isLocked(), 'Bütün bölmələr göndərildi — brif kilidlənməli idi.');
        $this->assertSame(100, (int) $brief->progress);

        // Studiya cavabları ETİKETLƏ görür — xam `opt-…` açarları yox.
        $this->asStaff('owner');
        $html = Livewire::test(BriefAnswersRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])->html();

        $this->assertStringContainsString('Bakı, Nizami küç. 12', $html);
        $this->assertStringContainsString('Fərdi ev', $html);
        $this->assertStringContainsString('Modern', $html);
        $this->assertStringContainsString('Qonaq otağı, Kabinet', $html);
        $this->assertStringContainsString('Skandinav, Eko', $html);
        $this->assertStringContainsString(t('portal.yes'), $html);
        $this->assertStringNotContainsString('opt-', $html, 'Xam variant açarı adminə düşdü.');
        $this->assertStringContainsString('Sənan bəy üçün brif', $html, 'Vəziyyət sətrində brifin adı görünməlidir.');

        // Dizayner baxışı səhifəsi də açılır.
        $this->get(ProjectResource::getUrl('brief-review', ['record' => $project]))->assertOk()->assertSee('Bakı, Nizami küç. 12');
    }

    /** Məcburi sual cavabsızdırsa bölmə göndərilmir — fərdi brifdə də. */
    public function test_a_required_custom_question_blocks_section_submit_until_answered(): void
    {
        $owner = $this->asStaff('owner');
        $template = $this->customTemplate();
        $rm = $this->questionsManager($template);
        $rm->callTableAction('create', data: ['type' => 'text', 'label' => 'Ünvan', 'is_required' => true]);
        $rm->callTableAction('create', data: ['type' => 'text', 'label' => 'Qeyd']);

        app(BriefService::class)->present($this->studio->project, $template, $owner);
        $project = $this->studio->project;
        $section = $template->primarySection;
        $required = $template->fresh()->questions()->where('is_required', true)->firstOrFail();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->from(route('portal.brief.section', [$project, $section]))
            ->post(route('portal.brief.submit', [$project, $section]))
            ->assertSessionHasErrors('section');

        $this->assertFalse($project->fresh()->brief->isLocked());

        $this->save($project, $section, $required, 'Bakı')->assertOk();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.submit', [$project, $section]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($project->fresh()->brief->isLocked());
    }

    /**
     * «Göndərildi» xəbəri bir dəfə görünür. Əvvəl eyni flash həm portal
     * çərçivəsində, həm brif səhifəsində çap olunurdu, sonuncu bölmə isə
     * üstəlik «Brif dizaynerə göndərildi» kartını açırdı — üç blok bir xəbər.
     */
    public function test_submitting_sections_announces_it_exactly_once(): void
    {
        $owner = $this->asStaff('owner');
        $template = $this->customTemplate();
        $second = app(BriefBuilderService::class)->addSection($template, ['name' => 'İkinci bölmə']);
        $rm = $this->questionsManager($template);
        $rm->callTableAction('create', data: ['type' => 'text', 'label' => 'Birinci sual']);
        $rm->callTableAction('create', data: ['brief_section_id' => $second->id, 'type' => 'text', 'label' => 'İkinci sual']);

        app(BriefService::class)->present($this->studio->project, $template, $owner);
        $project = $this->studio->project;
        $client = $this->actingAs($this->studio->portalUser, 'customer');

        // Birinci bölmə: brif hələ açıqdır → «Bölmə göndərildi» bir dəfə.
        $html = $client->followingRedirects()
            ->post(route('portal.brief.submit', [$project, $template->primarySection]))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, e(t('portal.brief_section_submitted'))));
        $this->assertFalse($project->fresh()->brief->isLocked());

        // Sonuncu bölmə brifi bağlayır → yalnız «Brif dizaynerə göndərildi» kartı.
        $html = $client->followingRedirects()
            ->post(route('portal.brief.submit', [$project, $second]))
            ->assertOk()
            ->getContent();

        $this->assertTrue($project->fresh()->brief->isLocked());
        $this->assertSame(0, substr_count($html, e(t('portal.brief_section_submitted'))));
        $this->assertSame(1, substr_count($html, e(t('portal.brief_sent_title'))));
    }

    // ═══════════════════════ Köməkçilər ═══════════════════════

    private function asStaff(string $role, ?StudioWorld $world = null): User
    {
        $world ??= $this->studio;
        $user = $world->user($role);

        // Bir testdə aktyor dəyişəndə panelin `AuthenticateSession`-u sessiyadakı
        // köhnə parol hash-ı ilə uyğunsuzluq görüb 302 verir; Filament-in
        // mount-da atdığı 403 isə Livewire-in `redirect` bağlamasını dəyişib
        // qoyur. İkisi də növbəti sorğunu yalançı edir — bax RolesIsolationTest.
        $this->flushSession();
        while (SupportRedirects::$redirectorCacheStack !== []) {
            app()->instance('redirect', array_pop(SupportRedirects::$redirectorCacheStack));
        }

        AccessMatrix::flushCache();
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($world->tenant->id);

        return $user;
    }

    /**
     * Filament `FileUpload` state-i testdə də MASSİV gözləyir (uuid → fayl),
     * tək fayl olsa belə — çılpaq UploadedFile validasiyada tip xətası verir.
     *
     * @return array<int, UploadedFile>
     */
    private function img(string $name, int $width = 640, int $height = 480): array
    {
        return [UploadedFile::fake()->image($name, $width, $height)];
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    private function customTemplate(string $name = 'Fərdi brif'): BriefTemplate
    {
        return app(BriefBuilderService::class)->createTemplate($name, null, auth()->user());
    }

    private function questionsManager(BriefTemplate $template): Testable
    {
        return Livewire::test(QuestionsRelationManager::class, [
            'ownerRecord' => $template->fresh(),
            'pageClass' => EditBriefTemplate::class,
        ]);
    }

    private function save(Project $project, $section, BriefQuestion $question, mixed $value): TestResponse
    {
        return $this->actingAs($this->studio->portalUser, 'customer')
            ->patchJson(route('portal.brief.autosave', [$project, $section]), [
                'question_id' => $question->id,
                'value' => $value,
                'delegated' => false,
            ]);
    }
}
