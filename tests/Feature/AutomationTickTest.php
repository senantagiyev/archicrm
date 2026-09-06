<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\AutomationRule;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AutomationAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AutomationTickTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(): array
    {
        $owner = User::create([
            'name' => 'Sahib', 'email' => 'owner@test.az', 'password' => 'secret123', 'role' => 'owner',
        ]);
        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);
        $project = Project::create([
            'client_id' => $client->id, 'name' => 'Layihə', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $owner->id,
        ]);

        return [$owner, $client, $project];
    }

    public function test_overdue_invoice_alerts_finance_staff_when_rule_enabled(): void
    {
        Notification::fake();
        [$owner, $client, $project] = $this->scaffold();
        AutomationRule::create(['code' => 'rule-27', 'name' => 'Invoice overdue', 'trigger' => 't', 'priority' => 'critical', 'enabled' => true]);

        Invoice::create([
            'project_id' => $project->id, 'client_id' => $client->id, 'number' => 'INV-1',
            'issue_date' => now()->subDays(10), 'due_date' => now()->subDays(5),
            'total' => 1000, 'status' => 'sent',
        ]);

        $this->artisan('automation:tick')->assertSuccessful();

        Notification::assertSentTo($owner, AutomationAlert::class,
            fn (AutomationAlert $n) => $n->ruleCode === 'rule-27');
    }

    public function test_overdue_invoice_silent_when_rule_disabled(): void
    {
        Notification::fake();
        [$owner, $client, $project] = $this->scaffold();
        AutomationRule::create(['code' => 'rule-27', 'name' => 'Invoice overdue', 'trigger' => 't', 'priority' => 'critical', 'enabled' => false]);

        Invoice::create([
            'project_id' => $project->id, 'client_id' => $client->id, 'number' => 'INV-2',
            'issue_date' => now()->subDays(10), 'due_date' => now()->subDays(5),
            'total' => 1000, 'status' => 'sent',
        ]);

        $this->artisan('automation:tick')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_overdue_approval_reminds_client_when_rule_enabled(): void
    {
        Notification::fake();
        [$owner, $client, $project] = $this->scaffold();
        $clientUser = ClientUser::create([
            'client_id' => $client->id, 'name' => 'Portal User', 'email' => 'portal@test.az', 'password' => 'secret123',
        ]);
        AutomationRule::create(['code' => 'rule-16', 'name' => 'Approval reminder', 'trigger' => 't', 'priority' => 'critical', 'enabled' => true]);

        Approval::create([
            'approvable_type' => 'project', 'approvable_id' => $project->id, 'project_id' => $project->id,
            'client_user_id' => $clientUser->id, 'status' => 'pending', 'respond_by' => now()->subDays(3),
        ]);

        $this->artisan('automation:tick')->assertSuccessful();

        Notification::assertSentTo($clientUser, AutomationAlert::class,
            fn (AutomationAlert $n) => $n->ruleCode === 'rule-16');
    }
}
