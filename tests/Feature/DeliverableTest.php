<?php

namespace Tests\Feature;

use App\Enums\DeliverableStatus;
use App\Enums\DeliverableVersionStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Design\DeliverableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DeliverableTest extends TestCase
{
    use RefreshDatabase;

    private function make(): array
    {
        $user = User::create(['name' => 'U', 'email' => 'u@t.az', 'password' => 'secret123', 'role' => 'designer']);
        $client = Client::create(['name' => 'C', 'status' => 'client']);
        $project = Project::create([
            'client_id' => $client->id, 'name' => 'P', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);
        $deliverable = $project->deliverables()->create(['title' => 'Kitchen Layout', 'type' => 'layout']);

        return [$user, $client, $project, $deliverable];
    }

    public function test_new_version_supersedes_previous_and_points_current(): void
    {
        [$user, , , $deliverable] = $this->make();
        $svc = app(DeliverableService::class);

        $v1 = $svc->createVersion($deliverable, 'a.pdf', $user);
        $v2 = $svc->createVersion($deliverable, 'b.pdf', $user);

        $this->assertSame(1, $v1->version_number);
        $this->assertSame(2, $v2->version_number);
        $this->assertSame(DeliverableVersionStatus::Superseded, $v1->fresh()->status);
        $this->assertSame($v2->id, $deliverable->fresh()->current_version_id);
    }

    public function test_approved_version_is_immutable(): void
    {
        [$user, , , $deliverable] = $this->make();
        $version = app(DeliverableService::class)->createVersion($deliverable, 'a.pdf', $user);
        $version->forceFill(['status' => DeliverableVersionStatus::Approved])->save();

        $this->expectException(\RuntimeException::class);
        $version->update(['file_path' => 'hacked.pdf']);
    }

    public function test_illegal_transition_is_blocked(): void
    {
        [$user, , , $deliverable] = $this->make();
        $version = app(DeliverableService::class)->createVersion($deliverable, 'a.pdf', $user);

        // draft → approved is not allowed (must go through the flow).
        $this->expectException(\RuntimeException::class);
        $version->transitionTo(DeliverableVersionStatus::Approved);
    }

    public function test_send_then_approve_locks_version(): void
    {
        Notification::fake();
        [$user, , , $deliverable] = $this->make();
        $svc = app(DeliverableService::class);
        $approvals = app(ApprovalService::class);

        $svc->createVersion($deliverable, 'a.pdf', $user);
        $approval = $approvals->request($deliverable, $user);          // → sent_for_approval
        $this->assertSame(DeliverableVersionStatus::SentForApproval, $deliverable->fresh()->currentVersion->status);

        $approvals->decide($approval, true, null);                     // customer approves
        $deliverable->refresh();
        $this->assertSame(DeliverableVersionStatus::Locked, $deliverable->currentVersion->status);
        $this->assertSame(DeliverableStatus::Approved, $deliverable->status);
    }
}
