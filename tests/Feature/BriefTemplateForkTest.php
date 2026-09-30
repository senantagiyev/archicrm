<?php

namespace Tests\Feature;

use App\Filament\Resources\BriefTemplateResource;
use App\Filament\Resources\BriefTemplateResource\Pages\EditBriefTemplate;
use App\Filament\Resources\BriefTemplateResource\Pages\ListBriefTemplates;
use App\Filament\Resources\BriefTemplateResource\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\BriefTemplateResource\RelationManagers\SectionsRelationManager;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\BriefAnswersRelationManager;
use App\Models\BriefAnswer;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\User;
use App\Services\Brief\BriefBuilderService;
use App\Services\Brief\BriefService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\BriefQuestionBankSeeder;
use Database\Seeders\TranslationSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Sistem şablonunun redaktəsi — copy-on-write nüsxə.
 *
 * Sistem şablonu (Quick, Yaşayış, Kommersiya) bütün studiyalar üçün ortaqdır.
 * «Redaktə et» studiyaya TAM nüsxə verir; yoxlanılanlar:
 *  • nüsxə bank şablonunu bütövlükdə əks etdirir (bölmələr, otaq bölmələri,
 *    suallar, açarlar, şərti məntiq, qruplar, variantlar);
 *  • orijinal və başqa studiyalar heç nə hiss etmir; seeder nüsxəyə toxunmur;
 *  • nüsxə studiyanın siyahısında, göndərmə seçimində və yeni layihənin
 *    defoltunda orijinalın yerini tutur; silinəndə orijinal geri qayıdır;
 *  • redaktə şərti məntiqi, tərcümələri və bankın əlavə sahələrini itirmir;
 *    xüsusi tipli sualın konfiqi toxunulmaz qalır;
 *  • cavabı olan sual silinmir, gizlədilir — admin cavabı oxuya bilir;
 *  • bölmələr idarə olunur, sual bölmələr arasında köçürülür (otaq ↔ ümumi yox);
 *  • portal nüsxəni tam render edir və müştərinin cavabları nüsxəyə keçəndə
 *    itmir.
 */
class BriefTemplateForkTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private BriefTemplate $system;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TranslationSeeder::class);
        $this->seed(BriefQuestionBankSeeder::class);

        $this->studio = StudioWorld::make('fork');
        $this->system = BriefTemplate::default();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    // ═══════════════════════ 1. Nüsxənin özü ═══════════════════════

    public function test_the_edit_button_forks_the_whole_system_template_and_opens_the_copy(): void
    {
        $this->asStaff('owner');

        Livewire::test(ListBriefTemplates::class)
            ->assertTableActionVisible('customize', $this->system)
            ->assertTableActionHidden('edit', $this->system)
            ->callTableAction('customize', $this->system)
            ->assertRedirect();

        $fork = BriefTemplate::where('tenant_id', $this->studio->tenant->id)->firstOrFail();

        $this->assertSame($this->system->id, $fork->forked_from_id);
        $this->assertTrue($fork->isFork());
        $this->assertTrue($fork->isCustom());
        $this->assertSame($this->system->level, $fork->level, 'Nüsxə orijinalın qrupunda (Premium) qalmalıdır.');
        $this->assertSame($this->system->getTranslations('name'), $fork->getTranslations('name'));

        // Bölmələr: hamısı, otaq bölmələri room_type ilə, sıra eyni.
        $systemSections = $this->system->sections()->where('active', true)->get();
        $forkSections = $fork->sections()->get();
        $this->assertSame($systemSections->count(), $forkSections->count());
        $this->assertSame($systemSections->pluck('room_type')->all(), $forkSections->pluck('room_type')->all());
        $this->assertSame(
            $systemSections->map(fn ($s) => $s->getTranslation('name', 'az'))->all(),
            $forkSections->map(fn ($s) => $s->getTranslation('name', 'az'))->all(),
        );
        $this->assertTrue($forkSections->every(fn ($s) => str_starts_with($s->key, 'f'.$fork->id.'-')));

        // Suallar: açar, tip, variant, şərt, qrup, məcburilik — birə-bir.
        $shape = fn (BriefTemplate $t) => $t->questions()->get()
            ->map(fn (BriefQuestion $q) => [$q->key, $q->type, $q->options, $q->skip_logic, $q->group, $q->is_required, $q->getTranslations('label')])
            ->sortBy(fn ($row) => json_encode($row))->values()->all();

        $this->assertGreaterThan(100, $fork->questions()->count());
        $this->assertSame($shape($this->system), $shape($fork));
    }

    public function test_the_system_template_and_other_studios_are_untouched(): void
    {
        $owner = $this->asStaff('owner');
        $before = $this->snapshot($this->system);

        $fork = $this->fork($owner);
        $question = $this->forkQuestion($fork, 'object_type');

        app(BriefBuilderService::class)->updateQuestion($question, [
            'label' => 'Studiyamızın sualı', 'type' => 'select',
            'option_list' => [['value' => 'new', 'label' => 'Yeni bina'], ['value' => 'repair', 'label' => 'Təmir']],
        ]);

        $this->assertSame($before, $this->snapshot($this->system->fresh()), 'Orijinal şablon dəyişməməlidir.');

        // Başqa studiya: sistem şablonunu görür, bizim nüsxəni yox, öz nüsxəsini
        // ayrıca yarada bilir.
        $other = StudioWorld::make('fork-b');
        $stranger = $this->asStaff('owner', $other);

        Livewire::test(ListBriefTemplates::class)
            ->assertCanSeeTableRecords([$this->system])
            ->assertCanNotSeeTableRecords([$fork])
            ->assertTableActionVisible('customize', $this->system);

        $this->assertFalse($stranger->can('view', $fork));
        $this->assertFalse($stranger->can('update', $fork));
        $this->get(BriefTemplateResource::getUrl('edit', ['record' => $fork]))->assertNotFound();

        $theirs = app(BriefBuilderService::class)->forkTemplate($this->system, $stranger);
        $this->assertNotSame($fork->id, $theirs->id);
        $this->assertSame(
            $this->forkQuestion($this->system, 'object_type')->getTranslation('label', 'az'),
            $this->forkQuestion($theirs, 'object_type')->getTranslation('label', 'az'),
            'Başqa studiyanın nüsxəsi bizim redaktəni görməməlidir.',
        );
    }

    public function test_a_studio_keeps_a_single_copy_per_system_template(): void
    {
        $owner = $this->asStaff('owner');
        $fork = $this->fork($owner);

        $this->assertFalse($owner->can('customize', $this->system), 'Nüsxə varsa ikinci «Redaktə et» olmamalıdır.');
        $this->assertSame($fork->id, app(BriefBuilderService::class)->forkTemplate($this->system, $owner)->id);
        $this->assertSame(1, BriefTemplate::where('forked_from_id', $this->system->id)->count());

        // Nüsxənin nüsxəsi olmur — o onsuz da studiyanındır.
        $this->expectException(InvalidArgumentException::class);
        app(BriefBuilderService::class)->forkTemplate($fork, $owner);
    }

    public function test_only_full_brief_access_may_fork(): void
    {
        $viewer = $this->asStaff('visualizer');

        $this->assertFalse($viewer->can('customize', $this->system));

        Livewire::test(ListBriefTemplates::class)
            ->assertTableActionHidden('customize', $this->system);

        $this->assertSame(0, BriefTemplate::whereNotNull('forked_from_id')->count());
    }

    public function test_reseeding_the_bank_does_not_touch_the_copy(): void
    {
        $owner = $this->asStaff('owner');
        $fork = $this->fork($owner);
        $question = $this->forkQuestion($fork, 'object_type');

        app(BriefBuilderService::class)->updateQuestion($question, [
            'label' => 'Bizim variant', 'type' => 'select',
            'option_list' => [['value' => 'new', 'label' => 'A'], ['value' => 'repair', 'label' => 'B']],
        ]);

        $count = $fork->questions()->count();
        $this->seed(BriefQuestionBankSeeder::class);

        $this->assertSame('Bizim variant', $question->fresh()->getTranslation('label', 'az'));
        $this->assertSame($count, $fork->fresh()->questions()->count());
        $this->assertTrue($fork->sections()->get()->every->active);
    }

    // ═══════════════════════ 2. Kölgələmə və defolt ═══════════════════════

    public function test_the_copy_replaces_the_original_in_the_list_the_send_picker_and_new_projects(): void
    {
        $owner = $this->asStaff('owner');
        $fork = $this->fork($owner);

        Livewire::test(ListBriefTemplates::class)
            ->assertCanSeeTableRecords([$fork])
            ->assertCanNotSeeTableRecords([$this->system])
            ->assertTableActionVisible('edit', $fork)
            ->assertTableActionVisible('sendToProject', $fork);

        $catalog = BriefTemplate::catalogFor($this->studio->tenant->id)->pluck('id');
        $this->assertTrue($catalog->contains($fork->id));
        $this->assertFalse($catalog->contains($this->system->id));

        // Yeni layihənin brifi studiyanın versiyası ilə açılır.
        $this->assertSame($fork->id, BriefTemplate::defaultFor($this->studio->tenant->id)->id);
        $brief = $this->inTenant(fn () => app(BriefService::class)->forProject($this->studio->project));
        $this->assertSame($fork->id, $brief->brief_template_id);

        // Deaktiv nüsxə defolt deyil — amma orijinalı siyahıya geri gətirmir.
        $fork->forceFill(['active' => false])->save();
        $this->assertSame($this->system->id, BriefTemplate::defaultFor($this->studio->tenant->id)->id);
        $this->assertFalse(BriefTemplate::catalogFor($this->studio->tenant->id)->pluck('id')->contains($this->system->id));

        // Başqa studiyanın defoltu sistem şablonudur.
        $other = StudioWorld::make('fork-c');
        $this->assertSame($this->system->id, BriefTemplate::defaultFor($other->tenant->id)->id);
    }

    public function test_deleting_the_copy_restores_the_original_but_not_while_a_brief_uses_it(): void
    {
        $owner = $this->asStaff('owner');
        $fork = $this->fork($owner);

        $this->assertTrue($owner->can('delete', $fork));

        app(BriefService::class)->present($this->studio->project, $fork, $owner);
        AccessMatrix::flushCache();
        $this->assertFalse($owner->fresh()->can('delete', $fork->fresh()), 'Göndərilmiş nüsxə silinməməlidir.');

        // İstifadə olunmayan nüsxə silinir → orijinal qayıdır, yenidən redaktə mümkündür.
        $this->studio->project->brief->forceFill(['brief_template_id' => $this->system->id])->save();
        AccessMatrix::flushCache();

        Livewire::test(ListBriefTemplates::class)
            ->callTableAction('delete', $fork);

        $this->assertNull(BriefTemplate::find($fork->id));
        $this->assertSame(0, BriefSection::where('brief_template_id', $fork->id)->count());
        $this->assertTrue(BriefTemplate::catalogFor($this->studio->tenant->id)->pluck('id')->contains($this->system->id));
        $this->assertTrue($owner->fresh()->can('customize', $this->system));
    }

    // ═══════════════════════ 3. Redaktə nəyi qoruyur ═══════════════════════

    public function test_editing_keeps_skip_logic_group_translations_and_option_extras(): void
    {
        $owner = $this->asStaff('owner');

        // Bankdakı variant «formanın bilmədiyi» sahə daşıyır (rəng kodları),
        // sualın rus dilində etiketi var — nüsxəyə keçməli və redaktədən sağ çıxmalıdır.
        $systemQuestion = BriefQuestion::whereIn('brief_section_id', $this->system->sections()->select('id'))
            ->where('key', 'object_type')->firstOrFail();
        $options = $systemQuestion->options;
        $options[0]['colors'] = ['#fff', '#000'];
        $options[0]['label']['ru'] = 'Новостройка';
        $systemQuestion->forceFill([
            'options' => $options,
            'label' => $systemQuestion->getTranslations('label') + ['ru' => 'Тип объекта'],
            'skip_logic' => ['question' => 'contact_full_name', 'operator' => 'filled'],
            'group' => 'Obyekt',
        ])->save();

        $fork = $this->fork($owner);
        $question = $this->forkQuestion($fork, 'object_type');

        $this->questionsManager($fork)
            ->mountTableAction('edit', $question)
            ->assertTableActionDataSet(['label' => $systemQuestion->getTranslation('label', 'az'), 'type' => 'select'])
            ->setTableActionData([
                'label' => 'Obyekt nədir?',
                'option_list' => [
                    ['value' => $options[0]['value'], 'label' => 'Yeni tikili'],
                    ['value' => $options[1]['value'], 'label' => $options[1]['label']['az']],
                    ['value' => null, 'label' => 'Yeni variant'],
                ],
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $question->refresh();

        $this->assertSame('Obyekt nədir?', $question->getTranslation('label', 'az'));
        $this->assertSame('Тип объекта', $question->getTranslation('label', 'ru'), 'Rus etiketi itməməlidir.');
        $this->assertSame(['question' => 'contact_full_name', 'operator' => 'filled'], $question->skip_logic, 'Şərti məntiq redaktədə silinməməlidir.');
        $this->assertSame('Obyekt', $question->group);

        $this->assertCount(3, $question->options);
        $this->assertSame($options[0]['value'], $question->options[0]['value'], 'Variant açarı sabit qalmalıdır.');
        $this->assertSame('Yeni tikili', $question->options[0]['label']['az']);
        $this->assertSame('Новостройка', $question->options[0]['label']['ru']);
        $this->assertSame(['#fff', '#000'], $question->options[0]['colors'], 'Bankın əlavə sahələri qorunmalıdır.');
        $this->assertStringStartsWith('opt-', $question->options[2]['value']);
    }

    public function test_a_special_bank_type_keeps_its_structure_while_its_text_is_edited(): void
    {
        $owner = $this->asStaff('owner');
        $fork = $this->fork($owner);
        $inventory = $this->forkQuestion($fork, 'room_inventory');
        $config = $inventory->options;

        $this->questionsManager($fork)
            ->mountTableAction('edit', $inventory)
            ->assertTableActionDataSet(['type' => 'room_inventory'])
            ->setTableActionData(['label' => 'Hansı otaqlarınız var?'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $inventory->refresh();
        $this->assertSame('Hansı otaqlarınız var?', $inventory->getTranslation('label', 'az'));
        $this->assertSame('room_inventory', $inventory->type, 'Xüsusi tip dəyişdirilməməlidir.');
        $this->assertSame($config, $inventory->options, 'Otaq növlərinin siyahısı toxunulmaz qalmalıdır.');

        // Payload tipi dəyişməyə çalışsa belə (disabled sahə saxtalaşdırılıb) servis tipi saxlayır.
        app(BriefBuilderService::class)->updateQuestion($inventory, ['label' => 'Otaqlar', 'type' => 'text']);
        $this->assertSame('room_inventory', $inventory->fresh()->type);
        $this->assertSame($config, $inventory->fresh()->options);

        // Konstruktor xüsusi tipdə YENİ sual yaratmır.
        $this->expectException(InvalidArgumentException::class);
        app(BriefBuilderService::class)->addQuestion($fork, ['type' => 'matrix', 'label' => 'x']);
    }

    public function test_an_answered_question_is_hidden_not_deleted_and_the_answer_stays_readable(): void
    {
        $owner = $this->asStaff('owner');
        $fork = $this->fork($owner);
        $brief = app(BriefService::class)->present($this->studio->project, $fork, $owner);

        $answered = $this->forkQuestion($fork, 'object_type');
        $untouched = $this->forkQuestion($fork, 'object_address');
        BriefAnswer::create([
            'brief_id' => $brief->id, 'brief_question_id' => $answered->id,
            'value' => $answered->options[0]['value'], 'answered_at' => now(),
        ]);

        $rm = $this->questionsManager($fork);
        $rm->callTableAction('delete', $answered);
        $rm->callTableAction('delete', $untouched);

        $this->assertNull(BriefQuestion::find($untouched->id), 'Cavabsız sual həqiqətən silinir.');

        $answered->refresh();
        $this->assertFalse($answered->active, 'Cavablı sual gizlədilməlidir.');
        $this->assertFalse($fork->fresh()->questions()->whereKey($answered->id)->exists(), 'Konstruktorda görünməməlidir.');

        // Admin cavabı sualın etiketi ilə oxuyur.
        $answer = BriefAnswer::where('brief_question_id', $answered->id)->firstOrFail();
        $this->assertSame($answered->getTranslation('label', 'az'), $answer->question->getTranslation('label', 'az'));
        $this->assertNotSame('—', $answer->question->displayValue($answer->value));

        // Portal gizli sualı artıq göstərmir.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$this->studio->project, $answered->section]))
            ->assertOk()
            ->assertDontSee($answered->getTranslation('label', 'az'));
    }

    // ═══════════════════════ 4. Bölmələr ═══════════════════════

    public function test_sections_can_be_added_renamed_and_questions_placed_and_moved(): void
    {
        $owner = $this->asStaff('owner');
        $fork = $this->fork($owner);

        $sections = Livewire::test(SectionsRelationManager::class, [
            'ownerRecord' => $fork, 'pageClass' => EditBriefTemplate::class,
        ]);

        $sections->callTableAction('create', data: ['name' => 'Bizim əlavə bölmə', 'intro' => 'Qısa izah'])
            ->assertHasNoTableActionErrors();

        $extra = BriefSection::where('brief_template_id', $fork->id)->latest('id')->firstOrFail();
        $this->assertSame('Bizim əlavə bölmə', $extra->getTranslation('name', 'az'));
        $this->assertSame('Qısa izah', $extra->getTranslation('intro', 'az'));
        $this->assertNull($extra->room_type);

        $first = $fork->sections()->firstOrFail();
        $original = $first->getTranslations('name');
        $sections->callTableAction('edit', $first, data: ['name' => 'Əsas məlumat', 'intro' => '', 'position' => $first->position, 'active' => true])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Əsas məlumat', $first->fresh()->getTranslation('name', 'az'));
        $this->assertSame(array_diff_key($original, ['az' => 1]), array_diff_key($first->fresh()->getTranslations('name'), ['az' => 1]));

        // Sual seçilmiş bölməyə düşür.
        $this->questionsManager($fork)
            ->callTableAction('create', data: ['brief_section_id' => $extra->id, 'type' => 'boolean', 'label' => 'Ev heyvanınız var?'])
            ->assertHasNoTableActionErrors();
        $new = BriefQuestion::where('brief_section_id', $extra->id)->firstOrFail();
        $this->assertSame('boolean', $new->type);

        // Ümumi bölmələr arasında köçürmə olur…
        $moved = $this->forkQuestion($fork, 'object_address');
        app(BriefBuilderService::class)->updateQuestion($moved, ['label' => $moved->getTranslation('label', 'az'), 'brief_section_id' => $extra->id]);
        $this->assertSame($extra->id, $moved->fresh()->brief_section_id);
        $this->assertSame('object_address', $moved->fresh()->key, 'Köçürmə açarı dəyişməməlidir.');

        // …otaq bölməsinə isə yox.
        $room = $fork->sections()->whereNotNull('room_type')->firstOrFail();
        try {
            app(BriefBuilderService::class)->updateQuestion($moved->fresh(), ['label' => 'x', 'brief_section_id' => $room->id]);
            $this->fail('Ümumi sual otaq bölməsinə köçürülməməlidir.');
        } catch (InvalidArgumentException) {
            $this->assertSame($extra->id, $moved->fresh()->brief_section_id);
        }

        // Başqa şablonun bölməsi qəbul edilmir.
        $this->expectException(InvalidArgumentException::class);
        app(BriefBuilderService::class)->addQuestion($fork, [
            'brief_section_id' => $this->system->sections()->firstOrFail()->id, 'type' => 'text', 'label' => 'yad',
        ]);
    }

    public function test_section_removal_rules_protect_answers_and_rooms(): void
    {
        $owner = $this->asStaff('owner');
        $fork = $this->fork($owner);
        $service = app(BriefBuilderService::class);

        $withQuestions = $fork->sections()->whereNull('room_type')->firstOrFail();
        $room = $fork->sections()->whereNotNull('room_type')->firstOrFail();

        foreach ([$withQuestions, $room] as $section) {
            try {
                $service->deleteSection($section);
                $this->fail('Bu bölmə silinməməlidir: '.$section->key);
            } catch (InvalidArgumentException) {
                $this->assertNotNull($section->fresh());
            }
        }

        // Sualı olan bölmə deaktiv edilir — portal onu göstərmir.
        $service->updateSection($withQuestions, ['name' => $withQuestions->getTranslation('name', 'az'), 'active' => false]);
        $this->assertFalse($withQuestions->fresh()->active);

        // Boş bölmə silinir.
        $empty = $service->addSection($fork, ['name' => 'Boş']);
        $service->deleteSection($empty);
        $this->assertNull($empty->fresh());

        // Sonuncu aktiv bölmə söndürülmür.
        $custom = $service->createTemplate('Tək bölməli', null, $owner);
        $this->expectException(InvalidArgumentException::class);
        $service->updateSection($custom->primarySection, ['name' => 'x', 'active' => false]);
    }

    public function test_renaming_a_single_section_brief_renames_its_section_but_a_copy_keeps_its_sections(): void
    {
        $owner = $this->asStaff('owner');
        $service = app(BriefBuilderService::class);

        $custom = $service->createTemplate('Köhnə ad', null, $owner);
        $service->renameTemplate($custom, 'Yeni ad', 'Təsvir');
        $this->assertSame('Yeni ad', $custom->fresh()->primarySection->getTranslation('name', 'az'));

        $fork = $this->fork($owner);
        $firstSectionName = $fork->sections()->firstOrFail()->getTranslation('name', 'az');
        $englishName = $fork->getTranslation('name', 'en', false);

        Livewire::test(EditBriefTemplate::class, ['record' => $fork->getRouteKey()])
            ->assertSee('şablonunun studiyanıza məxsus versiyasıdır')
            ->fillForm(['title' => 'Bizim Premium brif', 'summary' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $fork->refresh();
        $this->assertSame('Bizim Premium brif', $fork->getTranslation('name', 'az'));
        $this->assertSame($englishName, $fork->getTranslation('name', 'en', false), 'Digər dillər qorunmalıdır.');
        $this->assertSame($firstSectionName, $fork->sections()->firstOrFail()->getTranslation('name', 'az'), 'Çox bölməli brifdə birinci bölmə öz adını saxlayır.');
    }

    // ═══════════════════════ 5. Müştəri tərəfi ═══════════════════════

    public function test_switching_a_project_to_the_copy_keeps_the_clients_answers_and_the_portal_renders_it(): void
    {
        $owner = $this->asStaff('owner');

        // Müştəri orijinal şablonla doldurmağa başlayıb.
        $brief = app(BriefService::class)->present($this->studio->project, $this->system, $owner);
        $systemQuestion = BriefQuestion::whereIn('brief_section_id', $this->system->sections()->select('id'))
            ->where('key', 'object_type')->firstOrFail();
        $value = $systemQuestion->options[1]['value'];
        BriefAnswer::create(['brief_id' => $brief->id, 'brief_question_id' => $systemQuestion->id, 'value' => $value, 'answered_at' => now()]);

        // Studiya şablonu redaktə edir və layihəni öz versiyasına keçirir.
        $fork = $this->fork($owner);
        $forkQuestion = $this->forkQuestion($fork, 'object_type');
        $options = collect($forkQuestion->options)->map(fn ($o) => ['value' => $o['value'], 'label' => $o['label']['az']])->all();
        $options[1]['label'] = 'Studiyanın öz adı';
        app(BriefBuilderService::class)->updateQuestion($forkQuestion, ['label' => 'Obyekt nədir?', 'type' => 'select', 'option_list' => $options]);

        // «Brif şablonunu dəyiş» — mövcud brif nüsxəyə keçir (yeni brif yaranmır).
        Livewire::test(BriefAnswersRelationManager::class, [
            'ownerRecord' => $this->studio->project, 'pageClass' => EditProject::class,
        ])
            ->callTableAction('briefTemplate', data: ['brief_template_id' => $fork->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $this->studio->project->briefs()->count());
        $brief = $brief->fresh();
        $this->assertSame($fork->id, $brief->brief_template_id);
        $this->assertSame($value, app(BriefService::class)->valuesByKey($brief)['object_type'] ?? null, 'Cavab nüsxəyə keçməlidir.');

        // Xülasə paneli etiketi brifin ÖZ şablonundan götürür.
        $this->assertSame('Studiyanın öz adı', app(BriefService::class)->summaryPanel($brief)['type']);

        // Portal: bölmə siyahısı və redaktə edilmiş sual.
        $client = $this->actingAs($this->studio->portalUser, 'customer');
        $client->get(route('portal.brief', $this->studio->project))
            ->assertOk()
            ->assertSee($fork->sections()->firstOrFail()->getTranslation('name', 'az'));

        $client->get(route('portal.brief.section', [$this->studio->project, $forkQuestion->section]))
            ->assertOk()
            ->assertSee('Obyekt nədir?')
            ->assertSee('Studiyanın öz adı');

        // Orijinal şablonun bölməsi bu brifdə açılmır.
        $client->get(route('portal.brief.section', [$this->studio->project, $systemQuestion->section]))
            ->assertNotFound();
    }

    public function test_the_rooms_hub_hint_works_on_a_copy_whose_section_keys_differ(): void
    {
        $owner = $this->asStaff('owner');
        $fork = $this->fork($owner);
        app(BriefService::class)->present($this->studio->project, $fork, $owner);

        $hub = $this->forkQuestion($fork, 'room_inventory')->section;
        $this->assertNotSame('rooms_hub', $hub->key);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief', $this->studio->project))
            ->assertOk()
            ->assertSee(route('portal.brief.section', [$this->studio->project->id, $hub->id]), false);
    }

    // ═══════════════════════ Köməkçilər ═══════════════════════

    private function asStaff(string $role, ?StudioWorld $world = null): User
    {
        $world ??= $this->studio;
        $user = $world->user($role);

        // Aktyor dəyişəndə sessiya/redirect vəziyyəti təmizlənir — bax BriefBuilderTest.
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

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    private function fork(User $owner): BriefTemplate
    {
        return app(BriefBuilderService::class)->forkTemplate($this->system, $owner);
    }

    private function forkQuestion(BriefTemplate $fork, string $key): BriefQuestion
    {
        return BriefQuestion::whereIn('brief_section_id', BriefSection::where('brief_template_id', $fork->id)->select('id'))
            ->where('key', $key)
            ->where('active', true)
            ->firstOrFail();
    }

    private function questionsManager(BriefTemplate $template): Testable
    {
        return Livewire::test(QuestionsRelationManager::class, [
            'ownerRecord' => $template->fresh(),
            'pageClass' => EditBriefTemplate::class,
        ]);
    }

    /** @return array<int, mixed> şablonun bütün məzmununun izi */
    private function snapshot(BriefTemplate $template): array
    {
        return [
            $template->getTranslations('name'),
            BriefSection::where('brief_template_id', $template->id)->orderBy('id')->get(['id', 'key', 'name', 'active', 'position'])->toArray(),
            BriefQuestion::whereIn('brief_section_id', BriefSection::where('brief_template_id', $template->id)->select('id'))
                ->orderBy('id')->get(['id', 'key', 'label', 'type', 'options', 'skip_logic', 'active'])->toArray(),
        ];
    }
}
