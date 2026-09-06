<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Finance\ProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfitabilityReportTest extends TestCase
{
    use RefreshDatabase;

    private function seedFinance(): Project
    {
        $user = User::create(['name' => 'M', 'email' => 'm@test.az', 'password' => 'secret123', 'role' => 'owner']);
        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);
        $project = Project::create([
            'client_id' => $client->id, 'name' => 'L', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id, 'budget_plan' => 10000,
        ]);

        // Collected + pending + overdue payments.
        Payment::create(['project_id' => $project->id, 'title' => 'İlk', 'amount' => 3000, 'status' => 'paid', 'paid_at' => now()]);
        Payment::create(['project_id' => $project->id, 'title' => 'Növbəti', 'amount' => 2000, 'status' => 'pending', 'due_date' => now()->addDays(10)]);
        Payment::create(['project_id' => $project->id, 'title' => 'Gecikmiş', 'amount' => 1000, 'status' => 'overdue', 'due_date' => now()->subDays(5)]);

        Expense::create(['project_id' => $project->id, 'category' => 'material', 'amount' => 500, 'status' => 'approved', 'date' => now()]);

        // 120 min @ 50/hr = 100 labour.
        TimeEntry::create(['user_id' => $user->id, 'project_id' => $project->id, 'duration_minutes' => 120, 'hourly_cost_snapshot' => 50, 'source' => 'manual']);

        Invoice::create(['project_id' => $project->id, 'client_id' => $client->id, 'number' => 'INV-1', 'total' => 5000, 'paid_amount' => 1000, 'status' => 'sent', 'issue_date' => now(), 'due_date' => now()->addDays(20)]);
        Invoice::create(['project_id' => $project->id, 'client_id' => $client->id, 'number' => 'INV-2', 'total' => 2000, 'paid_amount' => 0, 'status' => 'overdue', 'issue_date' => now()->subDays(30), 'due_date' => now()->subDays(3)]);

        return $project;
    }

    public function test_portfolio_aggregates(): void
    {
        $this->seedFinance();
        $p = app(ProfitabilityService::class)->portfolio();

        $this->assertSame(3000.0, $p['collected']);
        $this->assertSame(6000.0, $p['receivable']);   // (5000-1000) + 2000
        $this->assertSame(2000.0, $p['overdue']);       // overdue invoice balance
        $this->assertSame(600.0, $p['cost']);           // 100 labour + 500 expenses
        $this->assertSame(2400.0, $p['gross_profit']);
        $this->assertSame(80.0, $p['margin']);
        $this->assertSame(10000.0, $p['projected']);
    }

    public function test_cash_forecast_buckets(): void
    {
        $this->seedFinance();
        $f = app(ProfitabilityService::class)->cashForecast();

        $this->assertSame(1000.0, $f['overdue']); // due 5 days ago
        $this->assertSame(2000.0, $f['d30']);      // due in 10 days
        $this->assertSame(0.0, $f['d60']);
    }

    public function test_per_project_memoized_result(): void
    {
        $project = $this->seedFinance();
        $svc = app(ProfitabilityService::class);
        $r = $svc->forProject($project);

        $this->assertSame(3000.0, $r['revenue']);
        $this->assertSame(600.0, $r['cost']);
        $this->assertSame(2400.0, $r['gross_profit']);
        $this->assertSame(10000.0, $r['projected_revenue']);
    }
}
