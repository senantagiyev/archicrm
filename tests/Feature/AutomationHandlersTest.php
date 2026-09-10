<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\AutomationRule;
use App\Models\BudgetLine;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Stage;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AutomationAlert;
use App\Services\Approvals\ApprovalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AutomationHandlersTest extends TestCase
{
    use RefreshDatabase;

    private function enable(string $code): void
    {
        AutomationRule::create(['code' => $code, 'name' => $code, 'trigger' => 't', 'priority' => 'high', 'enabled' => true]);
    }

    private function owner(string $email = 'owner@test.az'): User
    {
        return User::create(['name' => 'Sahib', 'email' => $email, 'password' => 'secret123', 'role' => 'owner']);
    }

    private function project(User $manager): Project
    {
        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);

        return Project::create([
            'client_id' => $client->id, 'name' => 'L', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $manager->id,
        ]);
    }

    public function test_rule_1_lead_created_assigns_owner_and_notifies(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $this->enable('rule-1');

        $lead = Lead::create(['first_name' => 'Ali', 'last_name' => 'Vəli', 'status' => 'new']);

        $this->assertSame($owner->id, $lead->fresh()->responsible_user_id);
        Notification::assertSentTo($owner, AutomationAlert::class,
            fn (AutomationAlert $n) => $n->ruleCode === 'rule-1' && $n instanceof ShouldQueue);
    }

    public function test_rule_1_off_by_default_leaves_lead_unassigned(): void
    {
        Notification::fake();
        $this->owner();

        $lead = Lead::create(['first_name' => 'Ali', 'last_name' => 'Vəli', 'status' => 'new']);

        $this->assertNull($lead->fresh()->responsible_user_id);
        Notification::assertNothingSent();
    }

    public function test_rule_28_payment_created_notifies_accounting(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $project = $this->project($owner);
        $this->enable('rule-28');

        Payment::create(['project_id' => $project->id, 'title' => 'İlk', 'amount' => 500, 'status' => 'pending']);

        Notification::assertSentTo($owner, AutomationAlert::class,
            fn (AutomationAlert $n) => $n->ruleCode === 'rule-28');
    }

    public function test_rule_18_rejected_approval_creates_revision_task(): void
    {
        $owner = $this->owner();
        $project = $this->project($owner);
        $stage = Stage::create(['project_id' => $project->id, 'name' => 'Mərhələ 1', 'position' => 1, 'status' => 'in_progress']);
        $line = BudgetLine::create(['project_id' => $project->id, 'work_type' => 'İş', 'qty' => 1, 'position' => 1]);
        $approval = Approval::create([
            'approvable_type' => 'budget_line', 'approvable_id' => $line->id, 'project_id' => $project->id,
            'requested_by_user_id' => $owner->id, 'status' => 'pending',
        ]);
        $this->enable('rule-18');

        app(ApprovalService::class)->decide($approval, false, 'Qiyməti dəqiqləşdirin.');

        $task = Task::where('project_id', $project->id)->first();
        $this->assertNotNull($task);
        $this->assertStringStartsWith('Düzəliş:', $task->title);
        $this->assertSame($owner->id, $task->assignee_user_id);
    }

    public function test_rule_2_lead_sla_alert_via_tick(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $this->enable('rule-2');

        $lead = Lead::create(['first_name' => 'Gec', 'last_name' => 'Lid', 'status' => 'new', 'responsible_user_id' => $owner->id]);
        // Age it past the SLA window.
        $lead->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('automation:tick')->assertSuccessful();

        Notification::assertSentTo($owner, AutomationAlert::class,
            fn (AutomationAlert $n) => $n->ruleCode === 'rule-2');
    }

    public function test_rule_24_late_delivery_alert_via_tick(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $project = $this->project($owner);
        $supplier = Supplier::create(['name' => 'Təchizatçı']);
        $this->enable('rule-24');

        PurchaseOrder::create([
            'supplier_id' => $supplier->id, 'project_id' => $project->id,
            'order_date' => now()->subDays(30), 'expected_delivery' => now()->subDays(3),
            'total' => 1000, 'status' => 'ordered',
        ]);

        $this->artisan('automation:tick')->assertSuccessful();

        Notification::assertSentTo($owner, AutomationAlert::class,
            fn (AutomationAlert $n) => $n->ruleCode === 'rule-24');
    }
}
