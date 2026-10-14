<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ApprovalResource\Pages\ListApprovals;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ApprovalsRelationManager;
use App\Models\Approval;
use App\Models\Document;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Services\Approvals\ApprovalService;
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
 * «Razılaşdırma yarat» — siyahıdan və layihənin tabından.
 *
 * Müştəri (studiya) panelde razılaşdırma yarada bilmirdi: `ApprovalResource`
 * `canCreate() = false` idi, siyahıda düymə yox idi, layihədə tab yox idi.
 * Burada yoxlanılır: düymə kimə görünür, sorğu `ApprovalService`-dən keçir
 * (versiya, cavab müddəti, müştəriyə bildiriş), müştəri portalda onu görür,
 * faylı açır və qərar verir; yad layihə/studiya obyekti göndərilə bilmir.
 */
class ApprovalCreateTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private StudioWorld $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Notification::fake();

        $this->studio = StudioWorld::make('apprcreate');
        $this->foreign = StudioWorld::make('apprforeign');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_the_create_button_is_shown_to_roles_that_may_send_approvals(): void
    {
        foreach (['owner', 'project_manager', 'designer'] as $role) {
            $this->asStaff($role);
            Livewire::test(ListApprovals::class)->assertActionVisible('createApproval');
        }

        // Mühasib siyahını görür (pul domeni), amma layihədə Redaktə hüququ yoxdur.
        $this->asStaff('accountant');
        Livewire::test(ListApprovals::class)->assertActionHidden('createApproval');

        // Vizualizator pul domenini görmür — siyahının özü bağlıdır.
        $visualizer = $this->asStaff('visualizer');
        $this->assertFalse($visualizer->can('create', Approval::class));
    }

    public function test_an_uploaded_file_is_sent_to_the_client_from_the_list_page(): void
    {
        $pm = $this->asStaff('project_manager');
        $project = $this->studio->project;
        $bytes = 'Qonaq otağının planı — v1';

        Livewire::test(ListApprovals::class)
            ->callAction('createApproval', data: [
                'project_id' => $project->id,
                'subject_type' => ApprovalService::SUBJECT_UPLOAD,
                'title' => 'Qonaq otağının planı',
                'document_type' => 'drawing',
                'file_path' => UploadedFile::fake()->createWithContent('plan.pdf', $bytes),
                'note' => 'Divarların yerinə baxın.',
                'respond_by' => now()->addDays(5)->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $approval = $this->inTenant(fn () => Approval::with('approvable')->latest('id')->first());

        $this->assertNotNull($approval);
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame($project->id, $approval->project_id);
        $this->assertSame($pm->id, $approval->requested_by_user_id);
        $this->assertSame(1, $approval->version);
        $this->assertSame('Divarların yerinə baxın.', $approval->note);
        $this->assertSame(now()->addDays(5)->toDateString(), $approval->respond_by->toDateString());
        $this->assertInstanceOf(Document::class, $approval->approvable);
        $this->assertTrue($approval->approvable->visible_to_client, 'Müştəri təsdiqlədiyi faylı görməlidir.');
        $this->assertSame('Sənəd: Qonaq otağının planı', $approval->subjectLabel());
        $this->assertSame($this->studio->tenant->id, $approval->tenant_id);

        Notification::assertSentTo($this->studio->portalUser, ApprovalRequested::class);

        // Portal: kart, qeyd və fayl linki görünür; fayl açılır; qərar verilir.
        $portal = $this->actingAs($this->studio->portalUser, 'customer');
        $portal->get(route('portal.approvals', $project->id))
            ->assertOk()
            ->assertSee('Sənəd: Qonaq otağının planı')
            ->assertSee('Divarların yerinə baxın.')
            ->assertSee(route('portal.documents.download', [$project->id, $approval->approvable->id]), false);

        $download = $portal->get(route('portal.documents.download', [$project->id, $approval->approvable->id]));
        $download->assertOk();
        $this->assertSame($bytes, $download->streamedContent());

        $portal->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);
    }

    public function test_the_deadline_defaults_to_the_projects_client_response_window(): void
    {
        $this->asStaff('owner');
        $project = $this->studio->project;
        $project->update(['client_response_days' => 7]);

        // Layihənin tabından: layihə sabitdir, müddət dərhal doludur.
        $this->relationManager()
            ->mountTableAction('createApproval')
            ->assertTableActionDataSet(['respond_by' => now()->addDays(7)->toDateString()]);

        // Siyahıdan: layihə seçiləndə doldurulur.
        Livewire::test(ListApprovals::class)
            ->mountAction('createApproval')
            ->setActionData(['project_id' => $project->id])
            ->assertActionDataSet(['respond_by' => now()->addDays(7)->toDateString()]);
    }

    public function test_an_existing_internal_document_is_sent_from_the_project_tab_and_becomes_visible(): void
    {
        $this->asStaff('designer');
        $document = $this->studio->internalDocument;

        $this->relationManager()
            ->callTableAction('createApproval', data: [
                'subject_type' => ApprovalService::SUBJECT_DOCUMENT,
                'subject_id' => $document->id,
            ])
            ->assertHasNoTableActionErrors();

        $approval = $this->inTenant(fn () => Approval::where('approvable_type', 'document')->where('approvable_id', $document->id)->first());

        $this->assertNotNull($approval);
        $this->assertTrue($document->fresh()->visible_to_client);
        // Müddət verilməyib → layihənin standart pəncərəsi (3 gün).
        $this->assertSame(now()->addDays(3)->toDateString(), $approval->respond_by?->toDateString());

        $this->relationManager()->assertCanSeeTableRecords([$approval]);
    }

    public function test_a_stage_can_be_sent_and_a_resend_supersedes_the_pending_request(): void
    {
        $this->asStaff('project_manager');
        $data = ['subject_type' => ApprovalService::SUBJECT_STAGE, 'subject_id' => $this->studio->stage->id];

        $this->relationManager()->callTableAction('createApproval', data: $data)->assertHasNoTableActionErrors();
        $this->relationManager()->callTableAction('createApproval', data: $data)->assertHasNoTableActionErrors();

        $rows = $this->inTenant(fn () => Approval::where('approvable_type', 'stage')->orderBy('version')->get());

        $this->assertCount(2, $rows);
        $this->assertSame(ApprovalStatus::Draft, $rows[0]->status, 'Köhnə sorğu əvəzlənməlidir.');
        $this->assertSame(ApprovalStatus::Pending, $rows[1]->status);
        $this->assertSame(2, $rows[1]->version);
    }

    public function test_a_member_cannot_send_for_a_project_or_object_they_do_not_belong_to(): void
    {
        $designer = $this->asStaff('designer');

        // Üzvü olmadığı layihə seçimdə yoxdur və policy də bağlıdır.
        $this->assertFalse($designer->can('createFor', [Approval::class, $this->studio->otherProject]));
        $this->assertTrue($designer->can('createFor', [Approval::class, $this->studio->project]));

        $before = $this->inTenant(fn () => Approval::count());

        // Başqa studiyanın sənədinin id-si öz layihəsinin adı ilə göndərilir.
        $this->relationManager()
            ->callTableAction('createApproval', data: [
                'subject_type' => ApprovalService::SUBJECT_DOCUMENT,
                'subject_id' => $this->foreign->clientDocument->id,
            ]);

        $this->assertSame($before, $this->inTenant(fn () => Approval::count()), 'Yad studiyanın sənədi razılaşdırmaya düşdü.');
        $this->assertFalse(
            Approval::withoutGlobalScopes()->where('approvable_type', 'document')->where('approvable_id', $this->foreign->clientDocument->id)->exists(),
        );
    }

    public function test_the_project_page_has_an_approvals_tab_with_the_create_button(): void
    {
        $this->asStaff('owner');

        $index = array_search(ApprovalsRelationManager::class, ProjectResource::getRelations(), true);
        $this->assertIsInt($index, 'Layihə səhifəsində «Razılaşdırmalar» tabı yoxdur.');

        $this->get(ProjectResource::getUrl('edit', ['record' => $this->studio->project]).'?relation='.$index)
            ->assertOk()
            ->assertSee('Razılaşdırma yarat');
    }

    public function test_an_archived_project_is_not_offered(): void
    {
        $owner = $this->asStaff('owner');
        $this->studio->otherProject->update(['status' => ProjectStatus::Archived]);

        $this->assertFalse($owner->can('createFor', [Approval::class, $this->studio->otherProject->fresh()]));
        $this->assertTrue($owner->can('createFor', [Approval::class, $this->studio->project]));
    }

    private function relationManager()
    {
        return Livewire::test(ApprovalsRelationManager::class, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
        ]);
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
