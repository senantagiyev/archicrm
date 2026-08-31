<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Project;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\TestCase;

class CoreFlowsTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): array
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'o@test.az', 'password' => 'secret123',
            'role' => 'owner',
        ]);

        $client = Client::create(['name' => 'Müştəri', 'status' => 'lead']);

        $project = Project::create([
            'client_id' => $client->id, 'name' => 'Test layihə', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);

        return [$user, $client, $project];
    }

    public function test_stage_and_project_readiness_recalculate_from_tasks(): void
    {
        [$user, , $project] = $this->makeProject();

        $stage1 = $project->stages()->create(['name' => 'Mərhələ 1', 'position' => 1, 'weight' => 1]);
        $stage2 = $project->stages()->create(['name' => 'Mərhələ 2', 'position' => 2, 'weight' => 1]);

        $t1 = $stage1->tasks()->create(['project_id' => $project->id, 'title' => 'A', 'status' => 'todo']);
        $stage1->tasks()->create(['project_id' => $project->id, 'title' => 'B', 'status' => 'todo']);

        $t1->update(['status' => TaskStatus::Done]);

        $this->assertSame(50, $stage1->fresh()->readiness);
        // Stage2 has no tasks → 0; weighted avg (50 + 0) / 2 = 25.
        $this->assertSame(25, (int) $project->fresh()->readiness);
        $this->assertNotNull($t1->fresh()->completed_at);
    }

    public function test_debt_formula_counts_only_approved_rows_minus_paid(): void
    {
        [, , $project] = $this->makeProject();

        $line = $project->budgetLines()->create([
            'work_type' => 'İş', 'qty' => 10, 'work_price' => 5, 'material_price' => 5,
        ]);
        $this->assertSame('100.00', $line->fresh()->total);
        $this->assertSame(0.0, (float) $project->fresh()->debt); // draft — not counted

        // approval_status is not mass-assignable (security); it moves via the
        // approval flow — forceFill mimics ApprovalService here.
        $line->forceFill(['approval_status' => 'approved'])->save();
        $this->assertSame(100.0, (float) $project->fresh()->debt);

        $project->payments()->create(['title' => 'Avans', 'amount' => 40, 'status' => 'paid', 'paid_at' => now()]);
        $this->assertSame(60.0, (float) $project->fresh()->debt);
    }

    public function test_approval_reject_requires_comment_and_approve_updates_subject(): void
    {
        Notification::fake();

        [$user, $client, $project] = $this->makeProject();
        $clientUser = ClientUser::create(['client_id' => $client->id, 'name' => 'C', 'email' => 'c@test.az']);

        $item = $project->procurementItems()->create(['name' => 'Divan', 'price' => 500, 'qty' => 1]);

        $service = app(ApprovalService::class);
        $approval = $service->request($item, $user);

        $this->assertSame(ApprovalStatus::Pending, $item->fresh()->approval_status);

        $this->expectException(InvalidArgumentException::class);
        $service->decide($approval, false, null, $clientUser);
    }

    public function test_approval_approve_flow(): void
    {
        Notification::fake();

        [$user, $client, $project] = $this->makeProject();
        $clientUser = ClientUser::create(['client_id' => $client->id, 'name' => 'C', 'email' => 'c@test.az']);

        $item = $project->procurementItems()->create(['name' => 'Divan', 'price' => 500, 'qty' => 2]);

        $service = app(ApprovalService::class);
        $approval = $service->request($item, $user);
        $service->decide($approval, true, null, $clientUser);

        $this->assertSame(ApprovalStatus::Approved, $item->fresh()->approval_status);
        $this->assertSame(1000.0, (float) $project->fresh()->debt);
    }

    public function test_portal_scoping_blocks_foreign_projects(): void
    {
        [, $client, $project] = $this->makeProject();

        $otherClient = Client::create(['name' => 'Başqa', 'status' => 'client']);
        $foreignUser = ClientUser::create(['client_id' => $otherClient->id, 'name' => 'F', 'email' => 'f@test.az']);

        $this->actingAs($foreignUser, 'customer')
            ->get(route('portal.projects.show', $project))
            ->assertNotFound();
    }
}
