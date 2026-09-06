<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Finance\ProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_profitability_combines_revenue_labour_and_expenses(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@t.az', 'password' => 'secret123', 'role' => 'designer', 'hourly_internal_cost' => 30]);
        $client = Client::create(['name' => 'C', 'status' => 'client']);
        $project = Project::create([
            'client_id' => $client->id, 'name' => 'P', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id, 'contract_value' => 5000,
        ]);

        // Revenue: two payments, one paid.
        $project->payments()->create(['title' => 'Avans', 'amount' => 2000, 'status' => 'paid', 'paid_at' => now()]);
        $project->payments()->create(['title' => 'Qalıq', 'amount' => 3000, 'status' => 'pending']);

        // Labour: 2h at 30/h = 60 (snapshot from user).
        TimeEntry::create(['user_id' => $user->id, 'project_id' => $project->id, 'duration_minutes' => 120]);

        // Expense: 500.
        $project->expenses()->create(['category' => 'material', 'amount' => 500, 'date' => now(), 'status' => 'approved']);

        $p = app(ProfitabilityService::class)->forProject($project);

        $this->assertSame(2000.0, $p['revenue']);
        $this->assertSame(60.0, $p['labor_cost']);
        $this->assertSame(500.0, $p['expenses']);
        $this->assertSame(560.0, $p['cost']);
        $this->assertSame(1440.0, $p['gross_profit']);
        $this->assertSame(72.0, $p['margin']); // 1440/2000*100
    }

    public function test_time_entry_snapshots_cost_and_duration(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u2@t.az', 'password' => 'secret123', 'role' => 'designer', 'hourly_internal_cost' => 40]);
        $client = Client::create(['name' => 'C', 'status' => 'client']);
        $project = Project::create(['client_id' => $client->id, 'name' => 'P', 'type' => 'apartment', 'status' => 'active', 'manager_user_id' => $user->id]);

        $entry = TimeEntry::create([
            'user_id' => $user->id, 'project_id' => $project->id,
            'started_at' => now()->subMinutes(90), 'ended_at' => now(),
        ]);

        $this->assertSame(90, $entry->fresh()->duration_minutes);
        $this->assertSame('40.00', (string) $entry->fresh()->hourly_cost_snapshot);
        $this->assertSame(60.0, $entry->fresh()->labourCost());
    }
}
