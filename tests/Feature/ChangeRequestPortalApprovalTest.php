<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\ChangeRequestStatus;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ChangeRequestsRelationManager;
use App\Models\Approval;
use App\Models\ChangeRequest;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Services\Approvals\ApprovalService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\TranslationSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Dəyişiklik sorğusunu müştəri portalda özü təsdiqləyir (müştəri tələbi, 2026-10-14).
 *
 * «Müştəriyə təsdiqə göndər» portalda razılaşdırma açır; müştərinin cavabı
 * sorğunun vəziyyətinə yazılır. Əvvəl müştərinin cavabını yalnız işçi
 * «Müştəri təsdiqlədi» düyməsi ilə qeyd edirdi.
 */
class ChangeRequestPortalApprovalTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TranslationSeeder::class);
        $this->studio = StudioWorld::make('crportal');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_the_client_approves_a_change_request_in_the_portal(): void
    {
        Notification::fake();
        $this->asStaff('project_manager');
        $cr = $this->readyForClient();

        $this->manager()
            ->callTableAction('to_waiting_client_approval', $cr, data: ['note' => 'Bar piştaxtası palıd ağacından olacaq.'])
            ->assertHasNoTableActionErrors();

        $cr->refresh();
        $this->assertSame(ChangeRequestStatus::WaitingClientApproval, $cr->status);

        $approval = Approval::where('approvable_type', $cr->getMorphClass())->where('approvable_id', $cr->id)->sole();
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame('Bar piştaxtası palıd ağacından olacaq.', $approval->note);
        Notification::assertSentTo($this->studio->portalUser, ApprovalRequested::class);

        // Portal: müştəri nəyə razılıq verdiyini görür.
        $portal = $this->actingAs($this->studio->portalUser, 'customer');
        $portal->get(route('portal.approvals', $this->studio->project->id))
            ->assertOk()
            ->assertSee('Dəyişiklik sorğusu: Divarın sökülməsi')
            ->assertSee('Divar sökülür, yerinə bar piştaxtası qurulur.')
            ->assertSee('+1 850.50 ₼')
            ->assertSee('+7 gün')
            ->assertSee('Bar piştaxtası palıd ağacından olacaq.');

        $portal->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])->assertRedirect();

        $this->assertSame(ChangeRequestStatus::Approved, $cr->fresh()->status);
        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);
        $this->assertSame($this->studio->portalUser->id, $approval->fresh()->client_user_id);

        // Panel: müştərinin cavabı statusun altında görünür.
        $this->asStaff('project_manager');
        $this->manager()->assertSee('Müştəri portalda: təsdiqlədi');
    }

    public function test_the_client_rejects_with_a_reason_and_no_revision_task_is_created(): void
    {
        $this->asStaff('project_manager');
        $cr = $this->readyForClient();
        $this->manager()->callTableAction('to_waiting_client_approval', $cr);
        $approval = app(ApprovalService::class)->pendingFor($cr);
        $tasksBefore = Task::withoutGlobalScopes()->count();

        $portal = $this->actingAs($this->studio->portalUser, 'customer');
        $portal->post(route('portal.approvals.decide', $approval), ['decision' => 'reject'])
            ->assertSessionHasErrors('comment');
        $portal->post(route('portal.approvals.decide', $approval), ['decision' => 'reject', 'comment' => 'Büdcə çox artır.'])
            ->assertRedirect();

        $this->assertSame(ChangeRequestStatus::Rejected, $cr->fresh()->status);
        $this->assertSame(Task::withoutGlobalScopes()->count(), $tasksBefore, 'Rədd edilmiş dəyişiklik üçün «Düzəliş» tapşırığı yaranmamalıdır.');

        $this->asStaff('project_manager');
        $this->manager()->assertSee('Müştəri portalda: rədd etdi')->assertSee('Büdcə çox artır.');
    }

    public function test_an_unapproved_draft_cannot_reach_the_client(): void
    {
        $this->asStaff('project_manager');
        $draft = $this->changeRequest('Qaralama');

        $this->expectException(InvalidArgumentException::class);
        app(ApprovalService::class)->request($draft, auth()->user());
    }

    public function test_an_answer_given_outside_the_portal_closes_the_portal_request(): void
    {
        $this->asStaff('project_manager');
        $cr = $this->readyForClient();
        $this->manager()->callTableAction('to_waiting_client_approval', $cr);
        $approval = app(ApprovalService::class)->pendingFor($cr);

        // Müştəri telefonla rədd etdi — səbəb məcburidir.
        $this->manager()
            ->callTableAction('to_rejected', $cr, data: ['comment' => ''])
            ->assertHasTableActionErrors(['comment']);
        $this->manager()
            ->callTableAction('to_rejected', $cr, data: ['comment' => 'Telefonla imtina etdi.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ChangeRequestStatus::Rejected, $cr->fresh()->status);
        $this->assertSame(ApprovalStatus::Rejected, $approval->fresh()->status, 'Portaldakı razılaşdırma da bağlanmalıdır.');

        // Portalda artıq cavab düyməsi yoxdur, təkrar qərar 403.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_cancelling_withdraws_the_open_portal_request(): void
    {
        $this->asStaff('project_manager');
        $cr = $this->readyForClient();
        $this->manager()->callTableAction('to_waiting_client_approval', $cr);
        $approval = app(ApprovalService::class)->pendingFor($cr);

        $this->manager()->callTableAction('to_cancelled', $cr)->assertHasNoTableActionErrors();

        $this->assertSame(ChangeRequestStatus::Cancelled, $cr->fresh()->status);
        $this->assertNotSame(ApprovalStatus::Pending, $approval->fresh()->status);
        $this->assertNull(app(ApprovalService::class)->pendingFor($cr));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.approvals', $this->studio->project->id))
            ->assertOk()
            ->assertDontSee('Dəyişiklik sorğusu: Divarın sökülməsi');
    }

    /** Studiyada təsdiqlənmiş, müştəriyə göndərilməyə hazır sorğu. */
    private function readyForClient(): ChangeRequest
    {
        return $this->changeRequest('Divarın sökülməsi', [
            'description' => 'Divar sökülür, yerinə bar piştaxtası qurulur.',
            'reason' => 'Müştəri açıq planlı mətbəx istəyir.',
            'cost_impact' => 1850.50,
            'schedule_impact_days' => 7,
            'status' => ChangeRequestStatus::WaitingInternalApproval,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function changeRequest(string $title, array $attributes = []): ChangeRequest
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => ChangeRequest::create([
            'project_id' => $this->studio->project->id,
            'title' => $title,
            'requested_by' => 'client',
            ...$attributes,
        ]));
    }

    private function manager(): Testable
    {
        return Livewire::test(ChangeRequestsRelationManager::class, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
        ]);
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
