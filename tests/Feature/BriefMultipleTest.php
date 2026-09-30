<?php

namespace Tests\Feature;

use App\Enums\BriefStatus;
use App\Filament\Resources\BriefTemplateResource\Pages\ListBriefTemplates;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\BriefAnswersRelationManager;
use App\Models\Brief;
use App\Models\BriefAnswer;
use App\Models\BriefTemplate;
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
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Bir layihədə bir neçə brif.
 *
 * Əvvəl layihəyə ikinci brif göndərmək yeni brif yaratmırdı — mövcudunun
 * şablonunu dəyişirdi: müştəri üç göndərişdən yalnız sonuncunu görürdü, əvvəlki
 * brif artıq göndərilmişdisə yeni şablon KİLİDLİ açılırdı (heç nə kliklənmirdi).
 */
class BriefMultipleTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TranslationSeeder::class);
        $this->seed(BriefQuestionBankSeeder::class);

        $this->studio = StudioWorld::make('multi');
        $this->owner = $this->asStaff();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_three_briefs_sent_to_one_project_are_three_separate_briefs_the_client_sees(): void
    {
        Notification::fake();

        $templates = collect(['Birinci brif', 'İkinci brif', 'Üçüncü brif'])->map(fn ($name) => $this->customWithQuestion($name));

        foreach ($templates as $template) {
            Livewire::test(ListBriefTemplates::class)
                ->callTableAction('sendToProject', $template, data: ['project_id' => $this->studio->project->id])
                ->assertHasNoTableActionErrors();
        }

        $briefs = Brief::where('project_id', $this->studio->project->id)->orderBy('id')->get();
        $this->assertCount(3, $briefs);
        $this->assertSame($templates->pluck('id')->all(), $briefs->pluck('brief_template_id')->all());
        $this->assertTrue($briefs->every->isPresented());
        Notification::assertSentToTimes($this->studio->portalUser, BriefPresented::class, 3);

        // Portal: siyahı — üç kart, hər biri öz brifinə aparır.
        $client = $this->actingAs($this->studio->portalUser, 'customer');
        $project = $this->studio->project;

        $client->get(route('portal.brief', $project))
            ->assertOk()
            ->assertSee('Birinci brif')
            ->assertSee('İkinci brif')
            ->assertSee('Üçüncü brif')
            ->assertSee(t('portal.brief_list_intro', ['count' => 3]));

        foreach ($briefs as $i => $brief) {
            $client->get(route('portal.brief', [$project, 'brief' => $brief->id]))
                ->assertOk()
                ->assertSee($templates[$i]->getTranslation('name', 'az'))
                ->assertSee(t('portal.brief_all_briefs'))
                ->assertSee(route('portal.brief.section', [$project->id, $templates[$i]->primarySection->id]), false);
        }

        // Cavab düz brifə yazılır: bölmə şablona, şablon layihədə tək brifə aiddir.
        $second = $templates[1];
        $question = $second->questions()->firstOrFail();
        $client->patchJson(route('portal.brief.autosave', [$project, $second->primarySection]), [
            'question_id' => $question->id, 'value' => 'Cavab', 'delegated' => false,
        ])->assertOk();

        $this->assertSame($briefs[1]->id, BriefAnswer::where('brief_question_id', $question->id)->value('brief_id'));
        $this->assertSame(0, BriefAnswer::whereIn('brief_id', [$briefs[0]->id, $briefs[2]->id])->count());
    }

    public function test_submitting_one_brief_does_not_lock_the_others(): void
    {
        $a = $this->customWithQuestion('A brifi');
        $b = $this->customWithQuestion('B brifi');
        $service = app(BriefService::class);
        $service->present($this->studio->project, $a, $this->owner);
        $service->present($this->studio->project, $b, $this->owner);

        $client = $this->actingAs($this->studio->portalUser, 'customer');
        $project = $this->studio->project;

        $client->post(route('portal.brief.submit', [$project, $a->primarySection]))->assertRedirect();

        $briefA = Brief::where('brief_template_id', $a->id)->firstOrFail();
        $briefB = Brief::where('brief_template_id', $b->id)->firstOrFail();
        $this->assertTrue($briefA->isLocked());
        $this->assertFalse($briefB->isLocked());

        $client->patchJson(route('portal.brief.autosave', [$project, $b->primarySection]), [
            'question_id' => $b->questions()->firstOrFail()->id, 'value' => 'hələ yazıram', 'delegated' => false,
        ])->assertOk();
    }

    public function test_resending_the_same_brief_or_presenting_over_a_draft_creates_no_duplicate(): void
    {
        Notification::fake();
        $service = app(BriefService::class);

        // Studiya «Brif» tabını açıb — qaralama yaranıb (defolt şablon).
        $draft = $this->inTenant(fn () => $service->forProject($this->studio->project));
        $this->assertFalse($draft->isPresented());

        // İlk göndəriş qaralamanı istifadə edir, ikinci brif yaratmır.
        $custom = $this->customWithQuestion('Fərdi');
        $sent = $service->present($this->studio->project, $custom, $this->owner);
        $this->assertSame($draft->id, $sent->id);
        $this->assertSame($custom->id, $sent->brief_template_id);

        // Eyni brifi təkrar göndərmək — nə yeni sətir, nə ikinci bildiriş.
        $again = $service->present($this->studio->project, $custom, $this->owner);
        $this->assertSame($sent->id, $again->id);
        $this->assertSame(1, Brief::where('project_id', $this->studio->project->id)->count());
        Notification::assertSentToTimes($this->studio->portalUser, BriefPresented::class, 1);
    }

    /** Kilidli brifin şablonu dəyişəndə müştəri yeni sualları doldura bilməlidir. */
    public function test_switching_the_template_of_a_submitted_brief_reopens_it(): void
    {
        $a = $this->customWithQuestion('Köhnə brif');
        $b = $this->customWithQuestion('Yeni brif');
        $service = app(BriefService::class);
        $brief = $service->present($this->studio->project, $a, $this->owner);

        $client = $this->actingAs($this->studio->portalUser, 'customer');
        $client->post(route('portal.brief.submit', [$this->studio->project, $a->primarySection]));
        $this->assertTrue($brief->fresh()->isLocked());

        $this->asStaff();
        Livewire::test(BriefAnswersRelationManager::class, ['ownerRecord' => $this->studio->project, 'pageClass' => EditProject::class])
            ->callTableAction('briefTemplate', data: ['brief_template_id' => $b->id])
            ->assertHasNoTableActionErrors();

        $brief->refresh();
        $this->assertSame($b->id, $brief->brief_template_id);
        $this->assertFalse($brief->isLocked(), 'Yeni şablonla brif yenidən açılmalıdır.');
        $this->assertSame(BriefStatus::Sent, $brief->statusEnum());

        $this->actingAs($this->studio->portalUser, 'customer')
            ->patchJson(route('portal.brief.autosave', [$this->studio->project, $b->primarySection]), [
                'question_id' => $b->questions()->firstOrFail()->id, 'value' => 'indi yaza bilirəm', 'delegated' => false,
            ])->assertOk();
    }

    public function test_a_template_already_used_in_the_project_cannot_be_switched_to(): void
    {
        $a = $this->customWithQuestion('A');
        $b = $this->customWithQuestion('B');
        $service = app(BriefService::class);
        $briefA = $service->present($this->studio->project, $a, $this->owner);
        $service->present($this->studio->project, $b, $this->owner);

        try {
            $service->switchTemplate($briefA, $b);
            $this->fail('Eyni şablondan ikinci brif yaranmamalıdır.');
        } catch (InvalidArgumentException) {
            $this->assertSame($a->id, $briefA->fresh()->brief_template_id);
        }

        // Paneldə də: seçim siyahısında yoxdur, payload ilə gəlsə — xəta, dəyişiklik yox.
        Livewire::test(BriefAnswersRelationManager::class, ['ownerRecord' => $this->studio->project, 'pageClass' => EditProject::class])
            ->callTableAction('briefTemplate', data: ['brief_id' => $briefA->id, 'brief_template_id' => $b->id]);

        $this->assertSame($a->id, $briefA->fresh()->brief_template_id);
        $this->assertSame(2, Brief::where('project_id', $this->studio->project->id)->count());
    }

    public function test_the_project_tab_sends_an_additional_brief_and_lists_answers_per_brief(): void
    {
        $a = $this->customWithQuestion('A brifi');
        $b = $this->customWithQuestion('B brifi');
        $briefA = app(BriefService::class)->present($this->studio->project, $a, $this->owner);

        $rm = Livewire::test(BriefAnswersRelationManager::class, ['ownerRecord' => $this->studio->project, 'pageClass' => EditProject::class])
            ->assertTableActionVisible('sendBrief');

        // İstifadə olunan şablon yeni brif siyahısında yoxdur — payload ilə gəlsə də rədd olunur.
        $rm->callTableAction('sendBrief', data: ['brief_template_id' => $a->id])
            ->assertHasTableActionErrors(['brief_template_id']);
        $this->assertSame(1, Brief::where('project_id', $this->studio->project->id)->count());

        Livewire::test(BriefAnswersRelationManager::class, ['ownerRecord' => $this->studio->project, 'pageClass' => EditProject::class])
            ->callTableAction('sendBrief', data: ['brief_template_id' => $b->id])
            ->assertHasNoTableActionErrors();

        $briefB = Brief::where('project_id', $this->studio->project->id)->where('brief_template_id', $b->id)->firstOrFail();
        $this->assertTrue($briefB->isPresented());
        $this->assertSame($a->id, $briefA->fresh()->brief_template_id, 'Əvvəlki brif dəyişməməlidir.');

        $answerA = BriefAnswer::create(['brief_id' => $briefA->id, 'brief_question_id' => $a->questions()->first()->id, 'value' => 'A cavabı', 'answered_at' => now()]);
        $answerB = BriefAnswer::create(['brief_id' => $briefB->id, 'brief_question_id' => $b->questions()->first()->id, 'value' => 'B cavabı', 'answered_at' => now()]);

        Livewire::test(BriefAnswersRelationManager::class, ['ownerRecord' => $this->studio->project->fresh(), 'pageClass' => EditProject::class])
            ->assertCanSeeTableRecords([$answerA, $answerB])
            ->assertSee('«A brifi»')
            ->assertSee('«B brifi»')
            ->filterTable('brief_id', $briefB->id)
            ->assertCanSeeTableRecords([$answerB])
            ->assertCanNotSeeTableRecords([$answerA]);
    }

    public function test_a_foreign_brief_id_or_an_unsent_template_section_is_not_found(): void
    {
        $a = $this->customWithQuestion('Göndərilən');
        $notSent = $this->customWithQuestion('Göndərilməyən');
        app(BriefService::class)->present($this->studio->project, $a, $this->owner);

        // Başqa layihənin brifi.
        $foreign = app(BriefService::class)->present($this->studio->otherProject, $notSent, $this->owner);

        $client = $this->actingAs($this->studio->portalUser, 'customer');
        $project = $this->studio->project;

        $client->get(route('portal.brief', [$project, 'brief' => $foreign->id]))->assertNotFound();
        $client->get(route('portal.brief.summary', [$project, 'brief' => $foreign->id]))->assertNotFound();

        // Bu layihəyə göndərilməmiş şablonun bölməsi.
        $client->get(route('portal.brief.section', [$project, $notSent->primarySection]))->assertNotFound();
        $client->patchJson(route('portal.brief.autosave', [$project, $notSent->primarySection]), [
            'question_id' => $notSent->questions()->firstOrFail()->id, 'value' => 'x', 'delegated' => false,
        ])->assertNotFound();
    }

    // ═══════════════════════ Köməkçilər ═══════════════════════

    private function asStaff(): User
    {
        $user = $this->studio->user('owner');

        $this->flushSession();
        while (SupportRedirects::$redirectorCacheStack !== []) {
            app()->instance('redirect', array_pop(SupportRedirects::$redirectorCacheStack));
        }

        AccessMatrix::flushCache();
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->studio->tenant->id);

        return $user;
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    private function customWithQuestion(string $name): BriefTemplate
    {
        $service = app(BriefBuilderService::class);
        $template = $service->createTemplate($name, null, $this->owner);
        $service->addQuestion($template, ['type' => 'text', 'label' => $name.' — sual']);

        return $template->fresh();
    }
}
