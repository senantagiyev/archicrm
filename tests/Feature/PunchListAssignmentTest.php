<?php

namespace Tests\Feature;

use App\Enums\PunchIssueStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\PunchListRelationManager;
use App\Models\PunchListIssue;
use App\Models\User;
use App\Notifications\PunchIssueAssigned;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Punch List: qüsur təyin olunan adam onu həqiqətən GÖRÜRMÜ.
 *
 * Əvvəl üç boşluq vardı: (1) məsul şəxs siyahısı studiyanın bütün işçiləri
 * idi — layihəni aça bilməyən adama da qüsur təyin olunurdu; (2) təyinatdan
 * sonra heç bir bildiriş getmirdi; (3) «mənə təyin olunanlar» deyə bir yer
 * yox idi. Burada hər üçü yoxlanılır.
 */
class PunchListAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('punchassign');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    // ─────────────── Kimə təyin etmək olar ───────────────

    public function test_only_people_who_can_open_the_project_are_offered_as_assignees(): void
    {
        $this->asStaff('project_manager');
        $staff = $this->studio->staff;

        $inactive = $this->inTenant(fn () => User::create([
            'name' => 'Deaktiv usta', 'email' => 'off@punchassign.test', 'password' => 'secret123',
            'role' => 'designer', 'is_active' => false,
        ]));
        $this->inTenant(fn () => $this->studio->project->members()->attach($inactive->id, ['project_role' => 'designer']));

        $ids = $this->inTenant(fn () => PunchListIssue::assignableUsers($this->studio->project)->pluck('id')->all());

        // Layihənin komandası və hər layihəni açan sahibkar.
        $this->assertContains($staff['owner']->id, $ids);
        $this->assertContains($staff['project_manager']->id, $ids);
        $this->assertContains($staff['designer']->id, $ids);
        $this->assertContains($staff['procurement']->id, $ids);

        // Üzv olmayan «öz layihələri» rolu layihəni aça bilmir — qüsuru da görməzdi.
        $this->assertNotContains($staff['visualizer']->id, $ids);
        // Mühasib layihəni yalnız oxuyur, redaktə səhifəsi ona bağlıdır.
        $this->assertNotContains($staff['accountant']->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_assigning_someone_without_project_access_is_rejected_by_the_form(): void
    {
        Notification::fake();
        $this->asStaff('project_manager');

        $this->relationManager()
            ->mountTableAction('create')
            ->setTableActionData([
                'title' => 'Yad adama qüsur',
                'priority' => 'normal',
                'status' => PunchIssueStatus::Open->value,
                'responsible_user_id' => $this->studio->user('visualizer')->id,
            ])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['responsible_user_id']);

        $this->assertFalse($this->inTenant(fn () => PunchListIssue::where('title', 'Yad adama qüsur')->exists()));
        Notification::assertNothingSent();
    }

    // ─────────────── Bildiriş ───────────────

    public function test_the_assignee_is_notified_when_an_issue_is_created_for_them(): void
    {
        Notification::fake();
        $this->asStaff('project_manager');
        $designer = $this->studio->user('designer');

        $this->relationManager()
            ->mountTableAction('create')
            ->setTableActionData([
                'title' => 'Plintus qopub',
                'room' => 'Dəhliz',
                'priority' => 'normal',
                'status' => PunchIssueStatus::Assigned->value,
                'responsible_user_id' => $designer->id,
                'due_date' => now()->addDays(3)->format('Y-m-d'),
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        Notification::assertSentTo($designer, PunchIssueAssigned::class, function (PunchIssueAssigned $n) use ($designer) {
            $data = $n->toDatabase($designer);

            // Panelin zəngi yalnız Filament formatlı sətirləri göstərir.
            return $data['format'] === 'filament'
                && str_contains($data['title'], 'Plintus qopub')
                && str_contains($data['actions'][0]['url'], 'relation=');
        });
        Notification::assertNotSentTo($this->studio->user('project_manager'), PunchIssueAssigned::class);
    }

    public function test_reassignment_notifies_the_new_assignee_only_and_other_edits_are_silent(): void
    {
        $issue = $this->issue('Rozetka işləmir', $this->studio->user('designer'));

        Notification::fake();
        $this->asStaff('project_manager');

        $this->inTenant(fn () => $issue->update(['status' => PunchIssueStatus::InProgress]));
        Notification::assertNothingSent();

        $this->inTenant(fn () => $issue->update(['responsible_user_id' => $this->studio->user('procurement')->id]));
        Notification::assertSentTo($this->studio->user('procurement'), PunchIssueAssigned::class);
        Notification::assertNotSentTo($this->studio->user('designer'), PunchIssueAssigned::class);
    }

    public function test_self_assignment_does_not_notify(): void
    {
        Notification::fake();
        $designer = $this->asStaff('designer');

        $this->issue('Özümə qeyd', $designer);

        Notification::assertNothingSent();
    }

    public function test_the_notification_lands_in_the_panel_bell(): void
    {
        $designer = $this->studio->user('designer');
        $this->asStaff('project_manager');

        $this->issue('Qapı tənzimlənməyib', $designer);

        // Zəngdə başqa işçi bildirişləri də (məs. StudioWorld-ün tapşırıq təyini)
        // Filament formatındadır — məhz qüsur bildirişi seçilir.
        $row = $designer->notifications()
            ->where('type', PunchIssueAssigned::class)
            ->where('data->format', 'filament')
            ->first();

        $this->assertNotNull($row, 'Bildiriş Filament formatında yazılmayıb — zəngdə görünməz.');
        $this->assertStringContainsString('Qapı tənzimlənməyib', $row->data['title']);
    }

    // ─────────────── Məsul şəxs qüsuru harada görür ───────────────

    public function test_the_assignee_can_open_the_link_from_the_notification(): void
    {
        $designer = $this->studio->user('designer');
        $issue = $this->issue('Kafel çatı', $designer);

        $this->asStaff('designer');

        $this->get(PunchListRelationManager::urlFor($this->studio->project))
            ->assertOk()
            ->assertSee('Kafel çatı');
    }

    public function test_the_assigned_to_me_filter_shows_only_my_items(): void
    {
        $mine = $this->issue('Mənim qüsurum', $this->studio->user('designer'));
        $theirs = $this->issue('Təchizatın qüsuru', $this->studio->user('procurement'));
        $nobody = $this->issue('Məsulsuz qüsur', null);

        $this->asStaff('designer');

        $this->relationManager()
            // Süzgəcsiz: layihənin ortaq siyahısı — üzv hamısını görür.
            ->assertCanSeeTableRecords([$mine, $theirs, $nobody])
            ->filterTable('assigned_to_me')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $nobody]);
    }

    public function test_the_dashboard_lists_my_open_punch_items(): void
    {
        $designer = $this->studio->user('designer');
        $open = $this->issue('Açıq qüsur', $designer);
        $rework = $this->issue('Yenidən görüləcək', $designer, PunchIssueStatus::Rejected);
        $resolved = $this->issue('Həll olunub', $designer, PunchIssueStatus::Resolved);
        $closed = $this->issue('Bağlanıb', $designer, PunchIssueStatus::Closed);
        $someoneElse = $this->issue('Başqasının', $this->studio->user('procurement'));

        $this->asStaff('designer');

        $ids = Livewire::test(Dashboard::class)->instance()->myPunchIssues()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$open->id, $rework->id], $ids);
        $this->assertNotContains($resolved->id, $ids);
        $this->assertNotContains($closed->id, $ids);
        $this->assertNotContains($someoneElse->id, $ids);

        Livewire::test(Dashboard::class)
            ->assertSee('Mənə təyin olunan qüsurlar')
            ->assertSee('Açıq qüsur');
    }

    public function test_the_dashboard_block_is_hidden_when_nothing_is_assigned(): void
    {
        $this->asStaff('visualizer');

        Livewire::test(Dashboard::class)->assertDontSee('Mənə təyin olunan qüsurlar');
    }

    // ═══════════════════════ Köməkçilər ═══════════════════════

    private function issue(string $title, ?User $responsible, PunchIssueStatus $status = PunchIssueStatus::Open): PunchListIssue
    {
        return $this->inTenant(fn () => PunchListIssue::create([
            'project_id' => $this->studio->project->id,
            'title' => $title,
            'responsible_user_id' => $responsible?->id,
            'status' => $status->value,
            'due_date' => now()->addDays(2)->toDateString(),
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

    private function relationManager()
    {
        return Livewire::test(PunchListRelationManager::class, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
        ]);
    }
}
