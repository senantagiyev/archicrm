<?php

namespace Tests\Feature;

use App\Enums\ChangeRequestStatus;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ChangeRequestsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\DecisionsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\PunchListRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\StagesRelationManager;
use App\Models\ChangeRequest;
use App\Models\ProjectDecision;
use App\Models\PunchListIssue;
use App\Models\Stage;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\ChangeSubjects;
use App\Support\TenantContext;
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
 * Dəyişiklik sorğuları və qərarlar öz obyektinin (material, mərhələ, irad)
 * daxilində (TZ §8, §9, §10; qəbul meyarı 17).
 *
 * Panel mövcud relation manager-ləri obyektə məhdudlaşdırılmış halda işlədir —
 * burada yoxlanılır ki, yaradılan qeyd obyektə bağlanır, yalnız orada görünür,
 * yad layihənin / studiyanın obyekti heç bir yoldan bağlana bilmir və daxili
 * qərarlar müştəri portalına çıxmır.
 */
class ChangesInContextTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TranslationSeeder::class);
        $this->studio = StudioWorld::make('ctx');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_a_change_request_created_from_a_stage_is_linked_to_it_and_shown_only_there(): void
    {
        $this->asStaff('project_manager');
        $stage = $this->studio->stage;
        $otherStage = $this->stage('Planlaşdırma');
        $legacy = $this->changeRequest('Köhnə əlaqəsiz sorğu');

        $this->scoped(ChangeRequestsRelationManager::class, $stage)
            ->assertSee('Mərhələ: Eskiz')
            ->callTableAction('create', data: [
                'title' => 'Divarın yerinin dəyişməsi',
                'requested_by' => 'client',
                'cost_impact' => 900,
                'schedule_impact_days' => 3,
            ])
            ->assertHasNoTableActionErrors();

        $cr = $this->inTenant(fn () => ChangeRequest::where('title', 'Divarın yerinin dəyişməsi')->sole());
        $this->assertSame('stage', $cr->affected_entity_type);
        $this->assertSame($stage->id, $cr->affected_entity_id);
        $this->assertSame(ChangeRequestStatus::Draft, $cr->status, 'Axın eynidir — qaralama ilə başlayır.');

        $this->scoped(ChangeRequestsRelationManager::class, $stage)
            ->assertCanSeeTableRecords([$cr])
            ->assertCanNotSeeTableRecords([$legacy])
            // İş axını panelin içində də eynidir.
            ->assertTableActionVisible('to_impact_assessment', $cr);

        $this->scoped(ChangeRequestsRelationManager::class, $otherStage)
            ->assertCanNotSeeTableRecords([$cr, $legacy]);

        // Ümumi siyahıda obyekt adı, köhnə qeyddə isə «Əlaqələndirilməyib» görünür.
        $this->manager(ChangeRequestsRelationManager::class)
            ->assertCanSeeTableRecords([$cr, $legacy])
            ->assertSee('Mərhələ: Eskiz')
            ->assertSee('Əlaqələndirilməyib');

        $this->assertEquals([$cr->id], $this->inTenant(fn () => ChangeRequest::forSubject($stage)->pluck('id')->all()));
        $this->assertEquals([$legacy->id], $this->inTenant(fn () => ChangeRequest::unlinked()->pluck('id')->all()));
    }

    public function test_a_decision_recorded_on_an_issue_is_linked_and_kept_in_its_history(): void
    {
        $this->asStaff('project_manager');
        $issue = $this->issue('Kafel çatlayıb');

        $this->scoped(DecisionsRelationManager::class, $issue)
            ->callTableAction('create', data: [
                'title' => 'Kafel tam dəyişdirilir',
                'source' => 'manual',
                'decision' => 'Çatlamış sıra sökülüb yenisi qoyulur.',
            ])
            ->assertHasNoTableActionErrors();

        $decision = $this->inTenant(fn () => ProjectDecision::sole());
        $this->assertSame('punch_list_issue', $decision->related_entity_type);
        $this->assertSame($issue->id, $decision->related_entity_id);
        $this->assertSame($this->studio->user('project_manager')->id, $decision->made_by_user_id);
        $this->assertNotNull($decision->decided_at, 'Hadisə lenti tarixə görə düzülür — tarix boş qalmasın.');

        $this->scoped(DecisionsRelationManager::class, $issue)->assertCanSeeTableRecords([$decision]);
        $this->scoped(DecisionsRelationManager::class, $this->studio->stage)->assertCanNotSeeTableRecords([$decision]);
    }

    public function test_the_stage_and_issue_rows_open_the_changes_panel(): void
    {
        $this->asStaff('project_manager');
        $issue = $this->issue('Rozetka əyridir');
        $this->inTenant(fn () => ChangeRequest::create([
            'project_id' => $this->studio->project->id,
            'title' => 'Rozetkanın yeri dəyişir',
            'affected_entity_type' => 'stage',
            'affected_entity_id' => $this->studio->stage->id,
        ]));

        $this->manager(StagesRelationManager::class)
            ->assertTableActionVisible('changesAndDecisions', $this->studio->stage)
            ->mountTableAction('changesAndDecisions', $this->studio->stage)
            ->assertMountedActionModalSee(['Mərhələ: Eskiz', 'Dəyişiklik sorğuları', 'Rozetkanın yeri dəyişir', 'Qərarlar']);

        $this->manager(PunchListRelationManager::class)
            ->assertTableActionVisible('changesAndDecisions', $issue)
            ->mountTableAction('changesAndDecisions', $issue)
            ->assertMountedActionModalSee('İrad: Rozetka əyridir')
            ->assertMountedActionModalDontSee('Rozetkanın yeri dəyişir');
    }

    public function test_a_scoped_manager_refuses_an_object_of_another_project(): void
    {
        $this->asStaff('owner');
        $foreignStage = $this->inTenant(fn () => $this->studio->otherProject->stages()->create([
            'name' => 'Yad mərhələ', 'position' => 1, 'weight' => 1, 'status' => 'not_started',
        ]));

        $this->scoped(ChangeRequestsRelationManager::class, $foreignStage)->assertStatus(404);
        $this->scoped(DecisionsRelationManager::class, $foreignStage)->assertStatus(404);

        // Naməlum növ də rədd olunur (məs. ödəniş).
        Livewire::test(ChangeRequestsRelationManager::class, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
            'subjectType' => 'payment',
            'subjectId' => $this->studio->payment->id,
        ])->assertStatus(404);
    }

    public function test_the_link_action_links_a_legacy_record_and_rejects_another_projects_object(): void
    {
        $this->asStaff('project_manager');
        $legacy = $this->changeRequest('Köhnə sorğu');
        $legacyDecision = $this->inTenant(fn () => ProjectDecision::create([
            'project_id' => $this->studio->project->id,
            'title' => 'Köhnə qərar',
            'source' => 'manual',
            'decision' => 'Parket palıd olacaq.',
        ]));
        $foreignStage = $this->inTenant(fn () => $this->studio->otherProject->stages()->create([
            'name' => 'Yad mərhələ', 'position' => 1, 'weight' => 1, 'status' => 'not_started',
        ]));

        // Yad layihənin obyekti seçim siyahısında yoxdur — forma rədd edir.
        $this->manager(ChangeRequestsRelationManager::class)
            ->assertTableActionVisible('linkToSubject', $legacy)
            ->callTableAction('linkToSubject', $legacy, data: ['subject' => 'stage:'.$foreignStage->id])
            ->assertHasTableActionErrors(['subject']);

        $this->assertNull($legacy->fresh()->affected_entity_type, 'Yad layihənin mərhələsinə bağlandı.');

        // Server tərəfi: birbaşa çağırış da bağlamır.
        try {
            $this->inTenant(fn () => ChangeSubjects::link($legacy->fresh(), $foreignStage));
            $this->fail('Yad layihənin obyekti bağlandı.');
        } catch (InvalidArgumentException) {
        }
        $this->assertNull($legacy->fresh()->affected_entity_type);

        // Başqa studiyanın eyni layihə nömrəli obyekti də keçmir.
        $foreignStudio = StudioWorld::make('ctxforeign');
        $this->assertNull($this->inTenant(fn () => ChangeSubjects::resolve($this->studio->project, 'stage', $foreignStudio->stage->id)));
        $this->assertFalse(ChangeSubjects::belongsTo($foreignStudio->stage, $this->studio->project));
        $this->asStaff('project_manager');

        // Düzgün obyekt — bağlanır və artıq yoxlama siyahısında deyil.
        $this->manager(ChangeRequestsRelationManager::class)
            ->callTableAction('linkToSubject', $legacy, data: ['subject' => 'stage:'.$this->studio->stage->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame('stage', $legacy->fresh()->affected_entity_type);
        $this->assertSame($this->studio->stage->id, $legacy->fresh()->affected_entity_id);
        $this->assertSame(0, $this->inTenant(fn () => ChangeRequest::unlinked()->count()));

        $issue = $this->issue('Plintus');
        $this->manager(DecisionsRelationManager::class)
            ->callTableAction('linkToSubject', $legacyDecision, data: ['subject' => 'punch_list_issue:'.$issue->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame('punch_list_issue', $legacyDecision->fresh()->related_entity_type);
        $this->assertSame($issue->id, $legacyDecision->fresh()->related_entity_id);
    }

    public function test_the_portal_does_not_expose_internal_decisions_or_object_links(): void
    {
        $this->asStaff('project_manager');

        $this->scoped(DecisionsRelationManager::class, $this->studio->stage)
            ->callTableAction('create', data: [
                'title' => 'Daxili qərar: podratçı dəyişdirilir',
                'source' => 'manual',
                'decision' => 'Gecikməyə görə köhnə podratçı ilə işi dayandırırıq.',
                'notes' => 'Müştəriyə hələ deməyin.',
            ])
            ->assertHasNoTableActionErrors();

        $cr = $this->changeRequest('Mətbəx adasının ölçüsü', [
            'status' => ChangeRequestStatus::WaitingInternalApproval,
            'affected_entity_type' => 'stage',
            'affected_entity_id' => $this->studio->stage->id,
            'estimated_hours' => 37.5,
        ]);

        $this->manager(ChangeRequestsRelationManager::class)
            ->callTableAction('to_waiting_client_approval', $cr)
            ->assertHasNoTableActionErrors();

        $portal = $this->actingAs($this->studio->portalUser, 'customer');

        foreach (['portal.approvals', 'portal.stages', 'portal.diary', 'portal.projects.show'] as $route) {
            $portal->get(route($route, $this->studio->project->id))
                ->assertOk()
                ->assertDontSee('Daxili qərar: podratçı dəyişdirilir')
                ->assertDontSee('Gecikməyə görə köhnə podratçı')
                ->assertDontSee('Müştəriyə hələ deməyin.')
                ->assertDontSee('Qərarlar jurnalı');
        }

        // Müştəri NƏYƏ razılıq verdiyini görür, amma daxili əmək sərfini yox.
        $portal->get(route('portal.approvals', $this->studio->project->id))
            ->assertSee('Mətbəx adasının ölçüsü')
            ->assertDontSee('37.5');
    }

    // ═══════════════════════ Köməkçilər ═══════════════════════

    private function scoped(string $manager, Stage|PunchListIssue $subject): Testable
    {
        return Livewire::test($manager, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
            'subjectType' => $subject->getMorphClass(),
            'subjectId' => $subject->id,
        ]);
    }

    private function manager(string $manager): Testable
    {
        return Livewire::test($manager, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
        ]);
    }

    private function stage(string $name): Stage
    {
        return $this->inTenant(fn () => $this->studio->project->stages()->create([
            'name' => $name, 'position' => 2, 'weight' => 1, 'status' => 'not_started',
        ]));
    }

    private function issue(string $title): PunchListIssue
    {
        return $this->inTenant(fn () => PunchListIssue::create([
            'project_id' => $this->studio->project->id,
            'title' => $title,
        ]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function changeRequest(string $title, array $attributes = []): ChangeRequest
    {
        return $this->inTenant(fn () => ChangeRequest::create([
            'project_id' => $this->studio->project->id,
            'title' => $title,
            'requested_by' => 'client',
            ...$attributes,
        ]));
    }

    private function asStaff(string $role): User
    {
        $user = $this->studio->user($role);

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
}
