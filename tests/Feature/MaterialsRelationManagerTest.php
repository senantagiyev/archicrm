<?php

namespace Tests\Feature;

use App\Enums\DeliverableVersionStatus;
use App\Enums\Domain;
use App\Enums\MaterialApprovalState;
use App\Enums\StageStatus;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\MaterialsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\StagesRelationManager;
use App\Models\Deliverable;
use App\Models\Role;
use App\Models\Stage;
use App\Models\StageTemplate;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Design\MaterialService;
use App\Services\Stages\StageTemplateService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * «Fayllar» material kartları — panel tərəfi (TZ «Layihə naviqasiyası» §4–6,
 * qəbul meyarları 3–9, 15–16, ssenari 1).
 */
class MaterialsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Notification::fake();

        $this->studio = StudioWorld::make('materials');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_a_material_uploaded_from_the_stage_context_gets_that_stage_and_the_filter_is_resettable(): void
    {
        $this->asStaff('designer');
        $stage = $this->studio->stage;
        $general = $this->material('Ümumi qeyd');

        $component = Livewire::withQueryParams(['stage' => $stage->id])
            ->test(MaterialsRelationManager::class, $this->rmParams())
            ->assertSet('tableFilters.stage_id.value', (string) $stage->id)
            ->assertCanNotSeeTableRecords([$general]);

        // Mərhələ formada göstərilmir — kontekstdən avtomatik gəlir.
        $component->callTableAction('create', data: [
            'files' => [UploadedFile::fake()->createWithContent('menzil-plan.pdf', '%PDF-1.4 plan')],
            'title' => 'Mənzilin plan həlli',
            'type' => 'layout',
            'visibility' => MaterialService::VISIBILITY_INTERNAL,
        ])->assertHasNoTableActionErrors();

        $material = $this->inTenant(fn () => Deliverable::where('title', 'Mənzilin plan həlli')->firstOrFail());

        $this->assertSame($stage->id, $material->stage_id, 'Mərhələdən yüklənən material həmin mərhələyə bağlanmalıdır.');
        $this->assertFalse($material->isPublished(), 'Daxili yüklənən material müştəriyə dərc olunmamalıdır.');
        $this->assertCount(1, $material->currentVersion->files());
        $this->assertSame('menzil-plan.pdf', $material->currentVersion->files()[0]['name']);

        $component->assertCanSeeTableRecords([$material])
            ->resetTableFilters()
            ->assertCanSeeTableRecords([$material, $general]);
    }

    public function test_a_general_material_may_have_no_stage_and_the_category_default_stage_is_offered(): void
    {
        $this->asStaff('designer');
        $planning = $this->inTenant(fn () => $this->studio->project->stages()->create([
            'name' => 'Planlaşdırma', 'position' => 2, 'weight' => 1, 'status' => 'not_started',
            'default_categories' => ['layout'],
        ]));

        $this->assertSame($planning->id, app(MaterialService::class)->defaultStageFor($this->studio->project, 'layout')?->id);

        Livewire::test(MaterialsRelationManager::class, $this->rmParams())
            ->callTableAction('create', data: [
                'files' => [UploadedFile::fake()->createWithContent('qeyd.pdf', '%PDF-1.4')],
                'title' => 'Ümumi qeyd',
                'type' => 'document',
                'stage_id' => null,
                'visibility' => MaterialService::VISIBILITY_INTERNAL,
            ])->assertHasNoTableActionErrors();

        $this->assertNull($this->inTenant(fn () => Deliverable::where('title', 'Ümumi qeyd')->value('stage_id')));
    }

    public function test_a_new_version_keeps_history_and_does_not_inherit_the_approval(): void
    {
        $pm = $this->asStaff('project_manager');
        $material = $this->material('Plan həlli', stage: $this->studio->stage);

        $approval = $this->inTenant(function () use ($material, $pm) {
            $approval = app(MaterialService::class)->sendForApproval($material, $material->currentVersion, $pm);
            app(ApprovalService::class)->decide($approval, true, null, $this->studio->portalUser);

            return $approval;
        });

        $this->assertSame(MaterialApprovalState::Approved, $this->inTenant(fn () => $material->fresh()->approvalState()));

        Livewire::test(MaterialsRelationManager::class, $this->rmParams())
            ->callTableAction('newVersion', $material, data: [
                'files' => [UploadedFile::fake()->createWithContent('plan-v2.pdf', '%PDF-1.4 v2')],
                'change_summary' => 'Mətbəx köçürüldü',
            ])->assertHasNoTableActionErrors();

        $material = $this->inTenant(fn () => $material->fresh(['versions', 'currentVersion']));

        $this->assertCount(2, $material->versions, 'Yeni versiya ayrıca kart yox, həmin karta əlavə olunmalıdır.');
        $this->assertSame(1, $this->inTenant(fn () => Deliverable::where('title', 'Plan həlli')->count()));
        $this->assertSame(2, $material->currentVersion->version_number);
        $this->assertSame(MaterialApprovalState::NotSent, $material->approvalState(), 'Yeni versiya köhnənin təsdiqini miras almamalıdır.');

        $v1 = $material->versions->firstWhere('version_number', 1);
        $this->assertSame(DeliverableVersionStatus::Locked, $v1->status, 'Təsdiqlənmiş v1 tarixçədə qalmalıdır.');
        $this->assertSame($v1->id, $material->published_version_id, 'Yeni daxili versiya dərc edilmiş versiyanı avtomatik əvəz etməməlidir.');
        $this->assertSame($v1->id, $approval->fresh()->deliverable_version_id);

        // Yeni versiya ayrıca razılaşdırmaya göndərilə bilir; köhnə qərar qalır.
        Livewire::test(MaterialsRelationManager::class, $this->rmParams())
            ->callTableAction('sendForApproval', $material, data: ['version_id' => $material->current_version_id])
            ->assertHasNoTableActionErrors();

        $material = $this->inTenant(fn () => $material->fresh());
        $this->assertSame(MaterialApprovalState::InApproval, $material->approvalState());
        $this->assertSame($material->current_version_id, $material->published_version_id, 'Razılaşdırılan versiya müştəriyə dərc olunmalıdır.');
        $this->assertCount(2, $this->inTenant(fn () => $material->approvalHistory()));
    }

    public function test_sending_for_approval_applies_the_projects_client_response_days(): void
    {
        $pm = $this->asStaff('project_manager');
        $this->studio->project->update(['client_response_days' => 4]);
        $material = $this->material('Vizual');

        $approval = $this->inTenant(fn () => app(MaterialService::class)->sendForApproval($material->fresh(), $material->currentVersion, $pm));

        $this->assertSame(now()->addDays(4)->toDateString(), $approval->respond_by->toDateString());
    }

    public function test_approving_a_material_does_not_complete_its_stage(): void
    {
        $pm = $this->asStaff('project_manager');
        $stage = $this->studio->stage;
        $material = $this->material('Konsepsiya', stage: $stage);

        $this->inTenant(function () use ($material, $pm) {
            $approval = app(MaterialService::class)->sendForApproval($material, $material->currentVersion, $pm);
            app(ApprovalService::class)->decide($approval, true, null, $this->studio->portalUser);
        });

        $stage = $this->inTenant(fn () => $stage->fresh());
        $this->assertSame(StageStatus::InProgress, $stage->status, 'Ayrı materialın təsdiqi mərhələni tamamlamamalıdır.');
        $this->assertNull($stage->date_fact_end);
    }

    public function test_an_approved_material_cannot_be_deleted(): void
    {
        $pm = $this->asStaff('project_manager');
        $material = $this->material('Təsdiqlənmiş');

        $this->inTenant(function () use ($material, $pm) {
            $approval = app(MaterialService::class)->sendForApproval($material, $material->currentVersion, $pm);
            app(ApprovalService::class)->decide($approval, true, null, $this->studio->portalUser);
        });

        Livewire::test(MaterialsRelationManager::class, $this->rmParams())
            ->assertTableActionHidden('delete', $material)
            ->assertTableActionHidden('sendForApproval', $material)
            ->assertTableActionVisible('newVersion', $material);
    }

    public function test_the_stages_tab_shows_the_materials_count_and_links_to_the_filtered_files_tab(): void
    {
        $this->asStaff('project_manager');
        $stage = $this->studio->stage;
        $this->material('Plan A', stage: $stage);
        $this->material('Plan B', stage: $stage);
        $this->material('Ümumi');

        $url = StagesRelationManager::materialsUrl($stage);
        $this->assertStringContainsString('relation=materials', $url);
        $this->assertStringContainsString('stage='.$stage->id, $url);

        Livewire::test(StagesRelationManager::class, $this->rmParams())
            ->assertTableColumnStateSet('deliverables_count', 2, $stage)
            ->assertTableActionHasUrl('openMaterials', $url, $stage);
    }

    public function test_a_direct_material_link_narrows_the_list_to_that_card(): void
    {
        $this->asStaff('project_manager');
        $target = $this->material('Hədəf');
        $other = $this->material('Başqa');

        Livewire::withQueryParams(['material' => $target->id])
            ->test(MaterialsRelationManager::class, $this->rmParams())
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_a_foreign_stage_in_the_url_is_ignored(): void
    {
        $this->asStaff('owner');
        $foreignStage = $this->inTenant(fn () => $this->studio->otherProject->stages()->create([
            'name' => 'Yad', 'position' => 1, 'weight' => 1, 'status' => 'not_started',
        ]));

        Livewire::withQueryParams(['stage' => $foreignStage->id])
            ->test(MaterialsRelationManager::class, $this->rmParams())
            ->assertSet('tableFilters.stage_id.value', null);
    }

    public function test_internal_comments_are_flagged_and_publish_is_separate_from_approval(): void
    {
        $designer = $this->asStaff('designer');
        $material = $this->material('Render');

        Livewire::test(MaterialsRelationManager::class, $this->rmParams())
            ->callTableAction('comment', $material, data: ['version_id' => $material->current_version_id, 'body' => 'Daxili: işığı düzəlt', 'is_internal' => true])
            ->callTableAction('publish', $material, data: ['version_id' => $material->current_version_id])
            ->assertHasNoTableActionErrors();

        $material = $this->inTenant(fn () => $material->fresh());
        $this->assertTrue($material->isPublished());
        $this->assertSame(MaterialApprovalState::NotSent, $material->approvalState(), 'Dərc etmək təsdiq demək deyil.');
        $this->assertSame(0, $this->inTenant(fn () => $material->approvals()->count()));
        $this->assertTrue($this->inTenant(fn () => $material->currentVersion->comments()->first()->is_internal));
        $this->assertSame($designer->id, $this->inTenant(fn () => $material->currentVersion->comments()->first()->author_id));
    }

    public function test_the_card_shows_versions_comments_and_approval_history(): void
    {
        $pm = $this->asStaff('project_manager');
        $material = $this->material('Kart yoxlaması');

        $this->inTenant(function () use ($material, $pm) {
            $service = app(MaterialService::class);
            $service->addComment($material->currentVersion, $pm, 'Komanda üçün qeyd', true);
            $approval = $service->sendForApproval($material, $material->currentVersion, $pm);
            app(ApprovalService::class)->decide($approval, false, 'Rəngi dəyişin', $this->studio->portalUser);
        });

        Livewire::test(MaterialsRelationManager::class, $this->rmParams())
            ->mountTableAction('view', $material)
            ->assertMountedActionModalSee([
                'Versiyalar',
                'Komanda üçün qeyd',
                'Daxili',
                'Razılaşdırma tarixçəsi',
                'Düzəliş tələb olunur',
                'Rəngi dəyişin',
                $this->studio->portalUser->name,
            ])
            ->assertMountedActionModalSee(route('materials.download', ['version' => $material->current_version_id, 'index' => 0]), false);
    }

    public function test_a_stage_template_carries_its_default_categories_to_the_project(): void
    {
        $this->asStaff('owner');
        $template = StageTemplate::create(['name' => ['az' => 'Test'], 'key' => 'mat_test', 'position' => 9, 'active' => true]);
        $template->items()->create(['name' => ['az' => 'Çertyojlar'], 'position' => 0, 'default_duration_days' => 5, 'default_categories' => ['drawing']]);

        $this->inTenant(fn () => app(StageTemplateService::class)->apply($this->studio->project, $template));

        $stage = $this->inTenant(fn () => $this->studio->project->stages()->where('name', 'Çertyojlar')->firstOrFail());
        $this->assertSame(['drawing'], $stage->default_categories);
        $this->assertSame($stage->id, $this->inTenant(fn () => app(MaterialService::class)->defaultStageFor($this->studio->project, 'drawing')?->id));
    }

    public function test_roles_without_files_permission_are_denied_server_side(): void
    {
        $material = $this->material('Gizli plan');
        $version = $material->currentVersion;
        Storage::disk('public')->put($version->file_path, 'plan');

        // Fayl domeni «Yoxdur» olan xüsusi rol.
        $role = $this->inTenant(fn () => Role::create([
            'tenant_id' => $this->studio->tenant->id,
            'key' => 'no_files',
            'name' => 'Fayl hüququ yoxdur',
            'levels' => collect(Domain::cases())->mapWithKeys(fn (Domain $d) => [$d->value => 2])->put(Domain::FilesDocuments->value, 0)->all(),
            'own_projects_only' => false,
            'is_system' => false,
            'active' => true,
        ]));
        $this->studio->user('accountant')->update(['role_id' => $role->id]);

        $user = $this->asStaff('accountant');
        $this->assertFalse(MaterialsRelationManager::canViewForRecord($this->studio->project, EditProject::class));
        $this->assertFalse($user->can('view', $material));
        $this->get(route('materials.download', ['version' => $version, 'index' => 0]))->assertForbidden();

        // Layihənin üzvü olmayan vizualizator (öz layihələri) — fayl verilmir.
        $this->asStaff('visualizer');
        $this->get(route('materials.download', ['version' => $version, 'index' => 0]))->assertForbidden();

        // Üzv dizayner — verilir.
        $this->asStaff('designer');
        $this->get(route('materials.download', ['version' => $version, 'index' => 0]))->assertOk();

        // Silinmiş materialın faylı heç kimə verilmir.
        $this->inTenant(fn () => $material->delete());
        $this->asStaff('owner');
        $this->get(route('materials.download', ['version' => $version, 'index' => 0]))->assertNotFound();
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function material(string $title, ?Stage $stage = null): Deliverable
    {
        return $this->inTenant(function () use ($title, $stage) {
            $path = 'materials/'.str($title)->slug().'.pdf';
            Storage::disk('public')->put($path, '%PDF-1.4');

            return app(MaterialService::class)->create($this->studio->project, [
                'title' => $title,
                'type' => 'layout',
                'stage_id' => $stage?->id,
                'files' => [$path],
                'file_names' => [$path => basename($path)],
            ], $this->studio->user('designer'))->load('currentVersion');
        });
    }

    /** @return array<string, mixed> */
    private function rmParams(): array
    {
        return ['ownerRecord' => $this->studio->project, 'pageClass' => EditProject::class];
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    private function asStaff(string $role): User
    {
        $user = $this->studio->user($role)->fresh();

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
