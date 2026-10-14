<?php

namespace Tests\Feature;

use App\Enums\ChangeRequestStatus;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ChangeRequestsRelationManager;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Layihənin «Dəyişiklik sorğuları» tabı.
 *
 * Müştəri şikayəti: tab xəta verirdi və nə üçün olduğu aydın deyildi. İki səbəb:
 *  1. Forma Filament 3-ün `Forms\Components\Section` sinfini işlədirdi — Filament
 *     4-də o yoxdur, «yarat» və «redaktə et» pəncərəsi «Class not found» ilə
 *     çökürdü (ona görə bazada bir dənə də sorğu yox idi).
 *  2. Policy hər sətirdə `$record->project`-ə baxırdı, əlaqə əvvəlcədən
 *     yüklənmirdi — iki sorğusu olan layihədə qeyri-production mühitdə cədvəl
 *     «Attempted to lazy load [project]» ilə çökürdü.
 */
class ChangeRequestsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('crrm');
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_the_create_and_edit_forms_open_and_save(): void
    {
        $owner = $this->asStaff('owner');

        $this->manager()
            ->assertSee('Razılaşdırılmış işdən sonra müştərinin istədiyi dəyişikliklər')
            ->mountTableAction('create')
            // Köhnə kodda məhz bu addım «Class "Filament\Forms\Components\Section" not found» atırdı.
            ->assertOk();

        $this->manager()
            ->callTableAction('create', data: [
                'title' => 'Mətbəxlə qonaq otağı arasındakı divarın sökülməsi',
                'description' => 'Divar sökülür, yerinə bar piştaxtası qurulur.',
                'reason' => 'Müştəri açıq planlı mətbəx istəyir.',
                'requested_by' => 'client',
                'responsible_user_id' => $this->studio->user('project_manager')->id,
                'cost_impact' => 1850.50,
                'schedule_impact_days' => 7,
                'estimated_hours' => 12,
            ])
            ->assertHasNoTableActionErrors();

        $cr = ChangeRequest::sole();
        $this->assertSame($this->studio->project->id, $cr->project_id);
        $this->assertSame('CR-'.$this->studio->project->id.'-001', $cr->number);
        $this->assertSame(ChangeRequestStatus::Draft, $cr->status);
        $this->assertSame('1850.50', (string) $cr->cost_impact);
        $this->assertSame(7, $cr->schedule_impact_days);
        $this->assertSame($owner->id, $cr->requested_by_user_id);
        $this->assertSame($this->studio->user('project_manager')->id, $cr->responsible_user_id);

        $this->manager()
            ->callTableAction('edit', $cr, data: ['cost_impact' => -200, 'schedule_impact_days' => -2])
            ->assertHasNoTableActionErrors();

        $this->assertSame('-200.00', (string) $cr->fresh()->cost_impact, 'Ucuzlaşma mənfi məbləğlə yazılır.');
        $this->assertSame(-2, $cr->fresh()->schedule_impact_days);
    }

    public function test_only_the_title_is_required(): void
    {
        $this->asStaff('project_manager');

        $this->manager()
            ->callTableAction('create', data: ['title' => ''])
            ->assertHasTableActionErrors(['title' => 'required']);

        $this->manager()
            ->callTableAction('create', data: [
                'title' => 'Plitkanın rənginin dəyişməsi',
                'description' => null,
                'reason' => null,
                'responsible_user_id' => null,
            ])
            ->assertHasNoTableActionErrors();

        $cr = ChangeRequest::sole();
        $this->assertNull($cr->description);
        $this->assertNull($cr->responsible_user_id);
        $this->assertSame('client', $cr->requested_by, 'Dəyişikliyi adətən müştəri istəyir.');
    }

    public function test_the_table_renders_many_rows_without_lazy_loading(): void
    {
        $this->asStaff('owner');

        $first = $this->changeRequest('Divarın yeri', ['cost_impact' => 1200, 'schedule_impact_days' => 5, 'reason' => 'Müştəri istədi']);
        $second = $this->changeRequest('Parketin əvəzi', ['requested_by' => 'team', 'responsible_user_id' => $this->studio->user('designer')->id]);
        $second->transitionTo(ChangeRequestStatus::ImpactAssessment);

        // Lokal/staging mühit kimi: lazy load istisna atır.
        Model::preventLazyLoading(true);

        $this->manager()
            ->assertOk()
            ->assertCanSeeTableRecords([$first, $second])
            ->assertSee('+5 gün')
            ->assertSee('Dəyişmir')
            ->assertSee('Təsiri hesablanır')
            ->assertTableActionVisible('to_impact_assessment', $first)
            ->assertTableActionHidden('to_impact_assessment', $second)
            ->assertTableActionVisible('to_waiting_internal_approval', $second)
            ->filterTable('status', ChangeRequestStatus::ImpactAssessment->value)
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first])
            ->resetTableFilters()
            ->filterTable('requested_by', 'team')
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$first]);
    }

    public function test_the_full_flow_up_to_client_approval_and_completion(): void
    {
        $this->asStaff('project_manager');
        $cr = $this->changeRequest('Əlavə rozetkalar', ['cost_impact' => 300, 'schedule_impact_days' => 2]);

        foreach ([
            ChangeRequestStatus::ImpactAssessment,
            ChangeRequestStatus::WaitingInternalApproval,
            ChangeRequestStatus::WaitingClientApproval,
            ChangeRequestStatus::Approved,
        ] as $to) {
            $this->manager()
                ->callTableAction('to_'.$to->value, $cr)
                ->assertHasNoTableActionErrors()
                // Müştəriyə göndəriş portalda razılaşdırma açır (ChangeRequestPortalApprovalTest).
                ->assertNotified($to === ChangeRequestStatus::WaitingClientApproval
                    ? 'Müştəriyə göndərildi — portalda təsdiq gözləyir'
                    : 'Vəziyyət: '.$to->label());

            $this->assertSame($to, $cr->fresh()->status);
        }

        // Müştəri təsdiqindən sonra razılaşdırılmış rəqəmlər dəyişdirilmir, sorğu silinmir.
        $this->manager()
            ->assertTableActionHidden('edit', $cr)
            ->assertTableActionHidden('delete', $cr)
            ->assertTableActionHidden('to_cancelled', $cr)
            ->callTableAction('to_in_implementation', $cr)
            ->callTableAction('to_completed', $cr->fresh());

        $this->assertSame(ChangeRequestStatus::Completed, $cr->fresh()->status);
        $this->assertSame('300.00', (string) $cr->fresh()->cost_impact);

        // Bağlanmış sorğuda heç bir növbəti addım qalmır.
        $manager = $this->manager();
        foreach (ChangeRequestStatus::cases() as $to) {
            if ($to === ChangeRequestStatus::Draft) {
                continue; // «qaralamaya qaytar» düyməsi ümumiyyətlə yoxdur
            }

            $manager->assertTableActionHidden('to_'.$to->value, $cr->fresh());
        }
    }

    public function test_client_rejection_and_cancellation_close_the_request(): void
    {
        $this->asStaff('owner');

        $rejected = $this->changeRequest('Tavanın hündürlüyü');
        $rejected->transitionTo(ChangeRequestStatus::ImpactAssessment);
        $rejected->transitionTo(ChangeRequestStatus::WaitingInternalApproval);
        $rejected->transitionTo(ChangeRequestStatus::WaitingClientApproval);

        $this->manager()
            ->assertTableActionVisible('to_approved', $rejected)
            ->callTableAction('to_rejected', $rejected)
            ->assertHasNoTableActionErrors();
        $this->assertSame(ChangeRequestStatus::Rejected, $rejected->fresh()->status);

        $cancelled = $this->changeRequest('Pərdə karnizi');
        $this->manager()->callTableAction('to_cancelled', $cancelled);
        $this->assertSame(ChangeRequestStatus::Cancelled, $cancelled->fresh()->status);

        // Rədd/ləğv edilmiş sorğu razılaşma deyil — silinə bilər.
        $this->manager()->callTableAction('delete', $cancelled);
        $this->assertModelMissing($cancelled);
    }

    public function test_an_illegal_jump_is_not_offered(): void
    {
        $this->asStaff('owner');
        $cr = $this->changeRequest('Qapının yeri');

        // Qaralamadan birbaşa «müştəri təsdiqlədi» olmaz — əvvəl təsir hesablanmalıdır.
        $this->manager()
            ->assertTableActionHidden('to_approved', $cr)
            ->assertTableActionHidden('to_waiting_client_approval', $cr)
            ->assertTableActionVisible('to_impact_assessment', $cr)
            ->assertTableActionVisible('to_cancelled', $cr);

        // Düymədən yan keçən birbaşa çağırış da model qoruyucusunda dayanır.
        $this->expectException(\RuntimeException::class);
        $cr->transitionTo(ChangeRequestStatus::Approved);
    }

    public function test_a_designer_prepares_the_request_but_only_owner_or_pm_decides(): void
    {
        $cr = $this->changeRequest('Divar kağızı', ['cost_impact' => 450]);

        $this->asStaff('designer');

        $this->manager()
            ->callTableAction('to_impact_assessment', $cr)
            ->callTableAction('to_waiting_internal_approval', $cr->fresh())
            ->assertHasNoTableActionErrors();
        $this->assertSame(ChangeRequestStatus::WaitingInternalApproval, $cr->fresh()->status);

        // Studiya adından təsdiq / rədd — dizaynerin işi deyil.
        $this->manager()
            ->assertTableActionHidden('to_waiting_client_approval', $cr->fresh())
            ->assertTableActionHidden('to_rejected', $cr->fresh())
            ->assertTableActionVisible('to_cancelled', $cr->fresh());

        $this->asStaff('project_manager');

        $this->manager()
            ->callTableAction('to_waiting_client_approval', $cr->fresh())
            ->assertHasNoTableActionErrors();
        $this->assertSame(ChangeRequestStatus::WaitingClientApproval, $cr->fresh()->status);

        // Müştərinin cavabını da yalnız qərar səlahiyyəti olan qeyd edir.
        $this->asStaff('designer');
        $this->manager()
            ->assertTableActionHidden('to_approved', $cr->fresh())
            ->assertTableActionHidden('to_rejected', $cr->fresh());
    }

    public function test_a_view_only_role_sees_the_list_without_actions(): void
    {
        $cr = $this->changeRequest('Divar kağızı');

        $this->asStaff('accountant');

        $this->manager()
            ->assertCanSeeTableRecords([$cr])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $cr)
            ->assertTableActionHidden('delete', $cr)
            ->assertTableActionHidden('to_impact_assessment', $cr)
            ->assertTableActionHidden('to_cancelled', $cr);
    }

    public function test_a_designer_outside_the_project_cannot_touch_its_requests(): void
    {
        $foreign = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => ChangeRequest::create([
            'project_id' => $this->studio->otherProject->id,
            'title' => 'Başqa layihə',
        ]));

        $designer = $this->asStaff('designer');

        $this->assertFalse($designer->can('update', $foreign));
        $this->assertFalse($designer->can('approve', $foreign));
        $this->assertFalse($designer->can('delete', $foreign));
    }

    private function manager(): Testable
    {
        return Livewire::test(ChangeRequestsRelationManager::class, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
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
