<?php

namespace Tests\Feature\Scenarios;

use App\Exceptions\PortalInvitationException;
use App\Models\ClientUser;
use App\Models\Stage;
use App\Models\Task;
use App\Notifications\TaskDeadlineSoon;
use App\Services\Portal\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Scenario: nothing exotic — the things a studio does in an ordinary week.
 * Archive a finished project. Move a task to another stage. Deactivate someone
 * who left. Invite a contact who already works with another client. Each of
 * these must be survivable; a studio cannot be told "don't archive projects".
 */
class DailyOperationScenarioTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('gundelik');
    }

    /**
     * Stages are not soft-deleted, so a stage of an archived project still
     * matches the nightly scanner — and its observer dereferences the now-null
     * project. One archived project takes down the scanner for the whole studio.
     */
    public function test_archiving_a_finished_project_does_not_break_the_nightly_overdue_scan(): void
    {
        $this->studio->stage->forceFill([
            'date_plan_end' => now()->subWeek(),
            'status' => 'in_progress',
        ])->save();

        $this->studio->project->delete(); // "Arxivləşdir" in the panel.

        // A second, live project that the scan must still reach.
        $liveStage = $this->studio->otherProject->stages()->create([
            'name' => 'Gecikmiş mərhələ', 'position' => 1, 'weight' => 1,
            'status' => 'in_progress', 'date_plan_end' => now()->subWeek(),
        ]);

        $this->artisan('stages:mark-overdue')->assertSuccessful();

        $this->assertSame(
            'overdue',
            $liveStage->fresh()->status->value,
            'Arxivlənmiş layihə gecikmə skanerini dayandırdı — canlı layihənin mərhələsi işarələnmədi.',
        );
    }

    public function test_editing_a_task_of_an_archived_project_does_not_crash(): void
    {
        $this->studio->project->delete();

        $this->studio->task->refresh()->update(['title' => 'Yenilənmiş başlıq']);

        $this->assertSame('Yenilənmiş başlıq', $this->studio->task->fresh()->title);
    }

    /**
     * A departing employee is deactivated, not hard-deleted. The deadline mailer
     * dereferences the assignee without a null check, so one such task stops
     * every deadline notification in the studio.
     */
    public function test_a_task_assigned_to_a_departed_employee_does_not_break_deadline_notices(): void
    {
        Notification::fake();

        // The command matches tasks whose deadline is exactly `deadline_days` away.
        $warningDay = today()->addDays((int) setting('notifications.deadline_days', 3));

        $this->studio->task->forceFill(['deadline' => $warningDay])->save();
        $this->studio->user('designer')->delete(); // soft delete — the employee left

        $liveTask = Task::create([
            'project_id' => $this->studio->project->id,
            'stage_id' => $this->studio->stage->id,
            'title' => 'Canlı tapşırıq',
            'status' => 'todo',
            'deadline' => $warningDay,
            'assignee_user_id' => $this->studio->user('project_manager')->id,
        ]);

        $this->artisan('tasks:notify-deadlines')->assertSuccessful();

        Notification::assertSentTo(
            $this->studio->user('project_manager'),
            TaskDeadlineSoon::class,
            fn (TaskDeadlineSoon $n, array $channels, $notifiable) => true,
        );

        $this->assertNotNull($liveTask->fresh(), 'Ilkin şərt: canlı tapşırıq mövcuddur.');
    }

    /**
     * Moving a task between stages must leave BOTH stages correct. The observer
     * recalculates `$task->stage` after `loadMissing`, and on an already-loaded
     * relation that is still the OLD stage — so exactly one of the two is
     * refreshed, and which one depends on whether the relation happened to be
     * loaded. Project readiness is the weighted average of these numbers.
     */
    public function test_moving_a_task_between_stages_leaves_both_stages_correct(): void
    {
        $from = $this->studio->stage;
        $to = $this->studio->project->stages()->create([
            'name' => 'Layihələndirmə', 'position' => 2, 'weight' => 1, 'status' => 'in_progress',
        ]);

        $done = Task::create([
            'project_id' => $this->studio->project->id, 'stage_id' => $from->id,
            'title' => 'Bitmiş', 'status' => 'done',
        ]);
        Task::create([
            'project_id' => $this->studio->project->id, 'stage_id' => $from->id,
            'title' => 'Açıq', 'status' => 'todo',
        ]);

        $this->studio->task->refresh()->delete(); // leave exactly the two tasks above

        $this->assertSame(50, $from->fresh()->readiness, 'Ilkin şərt: 2 tapşırıqdan 1-i bitib = 50%.');

        $done->update(['stage_id' => $to->id]);

        $this->assertSame(
            0,
            $from->fresh()->readiness,
            'Köhnə mərhələnin hazırlığı köçürmədən sonra yenilənmədi.',
        );
        $this->assertSame(
            100,
            $to->fresh()->readiness,
            'Yeni mərhələnin hazırlığı 0 qaldı — köçürülən tapşırıq nəzərə alınmadı (observer köhnə əlaqəni yenidən hesablayır).',
        );
    }

    /**
     * `client_users.email` is globally unique and the invite reuses the row by
     * email alone. One architect who is the contact for two clients means the
     * second invitation silently takes the account away from the first.
     */
    public function test_inviting_a_contact_who_already_belongs_to_another_client_does_not_steal_their_account(): void
    {
        Notification::fake();

        $shared = $this->studio->portalUser;
        $originalClientId = $shared->client_id;

        // Portal login is passwordless and keyed on the address, so one address
        // can only ever be one account. The invitation must say so, not silently
        // re-point the existing account at the new client.
        try {
            app(InvitationService::class)->invite(
                $this->studio->secondClient,
                'Eyni memar',
                $shared->email,
            );

            $this->fail('İkinci müştəriyə dəvət rədd edilmədi.');
        } catch (PortalInvitationException $e) {
            $this->assertStringContainsString('başqa bir müştərinin', $e->getMessage());
        }

        $this->assertSame(
            $originalClientId,
            ClientUser::find($shared->id)?->client_id,
            'İkinci müştəriyə dəvət mövcud portal hesabını ələ keçirdi — birinci müştəri öz layihələrinə girişi itirdi.',
        );
    }

    /**
     * Revoking portal access soft-deletes the ClientUser. Re-inviting the same
     * address must not silently resurrect a deliberately revoked account.
     */
    public function test_re_inviting_a_revoked_address_does_not_silently_restore_access(): void
    {
        Notification::fake();

        $revoked = $this->studio->portalUser;
        $revoked->delete();

        try {
            app(InvitationService::class)->invite($this->studio->client, $revoked->name, $revoked->email);

            $this->fail('Ləğv edilmiş hesaba dəvət rədd edilmədi.');
        } catch (PortalInvitationException $e) {
            $this->assertStringContainsString('ləğv edilib', $e->getMessage());
        }

        $this->assertSoftDeleted('client_users', ['id' => $revoked->id]);
    }

    /**
     * Delivery/assembly is money the client owes. It is stored on the row and
     * shown in the panel, but the total the debt formula reads ignores it.
     */
    public function test_procurement_delivery_cost_is_part_of_what_the_client_owes(): void
    {
        $item = $this->studio->procurementItem;
        $item->update(['qty' => 2, 'price' => 100, 'delivery_assembly_price' => 50]);

        $this->assertSame(
            '250.00',
            (string) $item->fresh()->total,
            'Çatdırılma/yığılma dəyəri pozisiyanın cəminə daxil edilmir — müştərinin borcu əskik hesablanır.',
        );
    }

    public function test_a_stage_belonging_to_an_archived_project_is_skipped_not_fatal(): void
    {
        $this->studio->project->delete();

        $orphaned = Stage::withoutGlobalScopes()->find($this->studio->stage->id);

        $this->assertNotNull($orphaned, 'Ilkin şərt: mərhələ arxivlənmiş layihədən sonra da qalır.');
        $this->assertNull($orphaned->project, 'Ilkin şərt: layihə əlaqəsi null-dır (soft delete).');

        // The observer path every stage write goes through.
        $orphaned->update(['status' => 'overdue']);

        $this->assertSame('overdue', $orphaned->fresh()->status->value);
    }
}
