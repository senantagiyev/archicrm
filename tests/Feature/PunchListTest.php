<?php

namespace Tests\Feature;

use App\Enums\PunchIssuePriority;
use App\Enums\PunchIssueStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\PunchListIssue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PunchListTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): Project
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'o@test.az', 'password' => 'secret123',
            'role' => 'owner',
        ]);

        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);

        return Project::create([
            'client_id' => $client->id, 'name' => 'Test layihə', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);
    }

    public function test_issue_is_created_with_default_open_status(): void
    {
        $project = $this->makeProject();

        $issue = PunchListIssue::create([
            'project_id' => $project->id,
            'room' => 'Mətbəx',
            'title' => 'Kafel çatlayıb',
        ]);

        $fresh = $issue->fresh();

        $this->assertSame(PunchIssueStatus::Open, $fresh->status);
        $this->assertSame(PunchIssuePriority::Normal, $fresh->priority);
        $this->assertTrue($fresh->project->is($project));
    }

    public function test_enum_and_date_casts(): void
    {
        $project = $this->makeProject();

        $issue = PunchListIssue::create([
            'project_id' => $project->id,
            'title' => 'Boya qüsuru',
            'priority' => PunchIssuePriority::Urgent->value,
            'status' => PunchIssueStatus::InProgress->value,
            'due_date' => '2026-09-20',
        ]);

        $fresh = $issue->fresh();

        $this->assertInstanceOf(PunchIssueStatus::class, $fresh->status);
        $this->assertInstanceOf(PunchIssuePriority::class, $fresh->priority);
        $this->assertSame(PunchIssueStatus::InProgress, $fresh->status);
        $this->assertSame(PunchIssuePriority::Urgent, $fresh->priority);
        $this->assertInstanceOf(Carbon::class, $fresh->due_date);
        $this->assertSame('2026-09-20', $fresh->due_date->format('Y-m-d'));
    }

    public function test_issue_belongs_to_responsible_user(): void
    {
        $project = $this->makeProject();

        $worker = User::create([
            'name' => 'Usta', 'email' => 'usta@test.az', 'password' => 'secret123',
            'role' => 'designer',
        ]);

        $issue = PunchListIssue::create([
            'project_id' => $project->id,
            'title' => 'Rozetka işləmir',
            'responsible_user_id' => $worker->id,
        ]);

        $this->assertSame($worker->id, $issue->fresh()->responsible->id);
    }
}
