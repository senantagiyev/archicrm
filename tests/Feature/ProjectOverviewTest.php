<?php

namespace Tests\Feature;

use App\Enums\ChangeRequestStatus;
use App\Enums\DeliverableStatus;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Models\ChangeRequest;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\ProjectDecision;
use App\Models\PunchListIssue;
use App\Models\User;
use App\Services\Projects\ProjectOverviewService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Layihə kartının «İcmal» tabı (TZ «Layihə naviqasiyası» §2, meyarlar 2, 13, 14, 17).
 */
class ProjectOverviewTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('overview');
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_the_overview_is_the_first_tab_for_the_owner_and_has_no_edit_form(): void
    {
        $this->asStaff('owner');
        $this->decision('Rəng palitrası seçildi');

        // Lokal mühit kimi: lazy load istisna atır — icmal N+1 sızdırmamalıdır.
        Model::preventLazyLoading(true);

        $this->get(ProjectResource::getUrl('edit', ['record' => $this->studio->project]))
            ->assertOk()
            ->assertSee('İcmal')
            ->assertSee('Sizdən gözlənilən əməliyyatlar')
            ->assertSee('Mühüm hadisələr')
            ->assertSee('İştirakçılar')
            ->assertSee($this->studio->client->name)
            ->assertSee('Eskiz')
            ->assertSee('Rəng palitrası seçildi')
            ->assertSee('Layihə parametrləri')
            ->assertSee('archi-project-section-select', false)
            // Layihənin forması bu səhifədə YOXDUR.
            ->assertDontSee('Müştərinin cavab müddəti')
            ->assertDontSee('Büdcə (plan)');

        Livewire::test(EditProject::class, ['record' => $this->studio->project->getKey()])
            ->assertFormFieldDoesNotExist('name')
            ->assertActionExists('settings')
            ->assertActionDoesNotExist('delete');
    }

    public function test_pending_actions_show_a_task_only_to_its_assignee(): void
    {
        $service = app(ProjectOverviewService::class);
        $designer = $this->asStaff('designer');

        $actions = $service->pendingActions($this->studio->project, $designer);
        $this->assertContains('Planlaşdırma', array_column($actions, 'title'));
        $task = collect($actions)->firstWhere('title', 'Planlaşdırma');
        $this->assertSame('Tapşırığı yerinə yetirin', $task['action']);
        $this->assertStringContainsString('/tasks/'.$this->studio->task->id.'/edit', $task['url']);

        $procurement = $this->asStaff('procurement');
        $this->assertNotContains('Planlaşdırma', array_column($service->pendingActions($this->studio->project, $procurement), 'title'));

        // Səhifənin özündə də.
        $this->asStaff('designer');
        $this->get(ProjectResource::getUrl('edit', ['record' => $this->studio->project]))
            ->assertOk()
            ->assertSee('Tapşırığı yerinə yetirin');

        $this->asStaff('procurement');
        $this->get(ProjectResource::getUrl('edit', ['record' => $this->studio->project]))
            ->assertOk()
            ->assertDontSee('Tapşırığı yerinə yetirin');
    }

    public function test_pending_actions_cover_punch_list_deliverables_and_internal_approval(): void
    {
        $service = app(ProjectOverviewService::class);
        $designer = $this->studio->user('designer');

        $this->inTenant(function () use ($designer): void {
            PunchListIssue::create([
                'project_id' => $this->studio->project->id,
                'title' => 'Plintus qopub',
                'responsible_user_id' => $designer->id,
                'due_date' => today()->subDay(),
            ]);

            Deliverable::create([
                'project_id' => $this->studio->project->id,
                'stage_id' => $this->studio->stage->id,
                'title' => 'Plan həlli',
                'status' => DeliverableStatus::RevisionRequired->value,
                'responsible_user_id' => $designer->id,
            ]);

            ChangeRequest::create([
                'project_id' => $this->studio->project->id,
                'title' => 'Divarın sökülməsi',
                'status' => ChangeRequestStatus::WaitingInternalApproval->value,
            ]);
        });

        $designerActions = collect($service->pendingActions($this->studio->project, $this->asStaff('designer')));

        $punch = $designerActions->firstWhere('title', 'Plintus qopub');
        $this->assertNotNull($punch);
        $this->assertTrue($punch['overdue']);
        $this->assertStringContainsString('relation=supervision', $punch['url']);
        // Gecikmiş element siyahının başındadır.
        $this->assertSame('Plintus qopub', $designerActions->first()['title']);

        $material = $designerActions->firstWhere('title', 'Plan həlli');
        $this->assertNotNull($material);
        $this->assertStringContainsString('relation=materials', $material['url']);
        $this->assertStringContainsString('stage='.$this->studio->stage->id, $material['url']);

        // Dizayner studiya adından təsdiqləyə bilməz (ChangeRequestPolicy::approve).
        $this->assertNull($designerActions->firstWhere('kind', 'Dəyişiklik sorğusu'));

        $pmActions = collect($service->pendingActions($this->studio->project, $this->asStaff('project_manager')));
        $this->assertNotNull($pmActions->firstWhere('kind', 'Dəyişiklik sorğusu'));
        // Başqasının iradı/materialı menecerin «sizdən gözlənilən» siyahısına düşmür.
        $this->assertNull($pmActions->firstWhere('title', 'Plintus qopub'));
    }

    public function test_the_feed_hides_sources_the_user_cannot_view(): void
    {
        $this->studio->project->members()->attach($this->studio->user('visualizer')->id, ['project_role' => 'visualizer']);
        $this->decision('Parket seçildi');

        $this->inTenant(function (): void {
            $deliverable = Deliverable::create([
                'project_id' => $this->studio->project->id,
                'title' => 'Vizual — qonaq otağı',
            ]);
            DeliverableVersion::create(['deliverable_id' => $deliverable->id, 'version_number' => 2]);
        });

        $service = app(ProjectOverviewService::class);

        $ownerFeed = implode("\n", array_column($service->feed($this->studio->project, $this->asStaff('owner')), 'text'));
        $this->assertStringContainsString('Razılaşdırmaya göndərildi: Smeta: Divar', $ownerFeed);
        $this->assertStringContainsString('Qərar qeydə alındı: Parket seçildi', $ownerFeed);
        $this->assertStringContainsString('Yeni versiya: Vizual — qonaq otağı (v2)', $ownerFeed);

        // Vizualizator: Smeta = Yoxdur, Komplektasiya = Yoxdur → razılaşdırmanı görmür
        // (ApprovalPolicy), qərar və material versiyası isə ona açıqdır.
        $visualizerFeed = implode("\n", array_column($service->feed($this->studio->project, $this->asStaff('visualizer')), 'text'));
        $this->assertStringNotContainsString('Smeta: Divar', $visualizerFeed);
        $this->assertStringContainsString('Qərar qeydə alındı: Parket seçildi', $visualizerFeed);
        $this->assertStringContainsString('Yeni versiya: Vizual — qonaq otağı (v2)', $visualizerFeed);

        // Layihənin üzvü olmayan «öz layihələri» rolu (yad studiyanın dizayneri) heç nə görmür.
        $foreign = StudioWorld::make('overviewforeign');
        AccessMatrix::flushCache();
        $this->assertSame([], app(ProjectOverviewService::class)->feed($this->studio->project, $foreign->user('designer')));
    }

    public function test_change_request_status_changes_and_unlinked_records_are_listed(): void
    {
        $pm = $this->asStaff('project_manager');

        $cr = ChangeRequest::create([
            'project_id' => $this->studio->project->id,
            'title' => 'Mətbəxin yeri',
        ]);
        $cr->transitionTo(ChangeRequestStatus::ImpactAssessment);
        $this->decision('Köhnə qərar');

        $service = app(ProjectOverviewService::class);

        $feed = collect($service->feed($this->studio->project, $pm));
        $statusEvent = $feed->first(fn (array $e) => str_contains($e['text'], '«Təsiri hesablanır»'));
        $this->assertNotNull($statusEvent);
        $this->assertSame($pm->name, $statusEvent['actor']);
        // Obyektə bağlanmayıb — keçid icmaldakı yoxlama siyahısına aparır.
        $this->assertStringEndsWith('#yoxlanmali-qeydler', $statusEvent['url']);

        $unchecked = $service->uncheckedRecords($this->studio->project, $pm);
        $titles = array_column($unchecked, 'title');
        $this->assertContains($cr->fresh()->number.' · Mətbəxin yeri', $titles);
        $this->assertContains('Köhnə qərar', $titles);

        $this->get(ProjectResource::getUrl('edit', ['record' => $this->studio->project]))
            ->assertOk()
            ->assertSee('Yoxlanmalı qeydlər');
    }

    public function test_the_mobile_menu_lists_every_section_and_switches_the_tab(): void
    {
        $this->asStaff('owner');

        $page = Livewire::test(EditProject::class, ['record' => $this->studio->project->getKey()]);
        $options = $page->instance()->sectionOptions();

        $this->assertSame('İcmal', $options['']);
        $this->assertContains('Brif', $options);
        $this->assertCount(count($page->instance()->getCachedRelationManagers()) + 1, $options);

        $firstKey = (string) array_key_first($page->instance()->getCachedRelationManagers());
        $page->set('activeRelationManager', $firstKey)
            ->assertSet('activeRelationManager', $firstKey)
            ->assertDontSee('Sizdən gözlənilən əməliyyatlar');
    }

    private function decision(string $title): ProjectDecision
    {
        return $this->inTenant(fn () => ProjectDecision::create([
            'project_id' => $this->studio->project->id,
            'title' => $title,
            'decision' => $title,
            'made_by_user_id' => $this->studio->user('project_manager')->id,
            'decided_at' => now(),
        ]));
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
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
}
