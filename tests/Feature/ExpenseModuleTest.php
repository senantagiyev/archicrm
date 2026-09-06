<?php

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseModuleTest extends TestCase
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

    public function test_expense_is_created_with_relations_and_casts(): void
    {
        [$user, , $project] = $this->makeProject();

        $expense = Expense::create([
            'project_id' => $project->id,
            'category' => ExpenseCategory::Supplier,
            'vendor' => 'ABC Təchizat',
            'amount' => 1250.50,
            'currency' => 'AZN',
            'date' => '2026-09-06',
            'description' => 'Material alışı',
            'status' => ExpenseStatus::Pending,
            'created_by_user_id' => $user->id,
        ]);

        $fresh = $expense->fresh();

        // Casts: enums + decimal + date.
        $this->assertInstanceOf(ExpenseCategory::class, $fresh->category);
        $this->assertSame(ExpenseCategory::Supplier, $fresh->category);
        $this->assertInstanceOf(ExpenseStatus::class, $fresh->status);
        $this->assertSame(ExpenseStatus::Pending, $fresh->status);
        $this->assertSame('1250.50', $fresh->amount);
        $this->assertSame('2026-09-06', $fresh->date->format('Y-m-d'));
        $this->assertSame('AZN', $fresh->currency);

        // Relations.
        $this->assertTrue($fresh->project->is($project));
        $this->assertTrue($fresh->creator->is($user));
    }

    public function test_expense_currency_defaults_to_azn(): void
    {
        Expense::create([
            'category' => ExpenseCategory::Other,
            'amount' => 100,
            'date' => now(),
            'status' => ExpenseStatus::Pending,
        ]);

        $this->assertSame('AZN', Expense::first()->currency);
    }

    public function test_expense_project_is_nullable(): void
    {
        $expense = Expense::create([
            'category' => ExpenseCategory::Software,
            'vendor' => 'SaaS',
            'amount' => 49.99,
            'date' => now(),
            'status' => ExpenseStatus::Approved,
        ]);

        $this->assertNull($expense->fresh()->project_id);
        $this->assertNull($expense->fresh()->project);
    }

    public function test_expense_soft_deletes(): void
    {
        [$user, , $project] = $this->makeProject();

        $expense = Expense::create([
            'project_id' => $project->id,
            'category' => ExpenseCategory::Transport,
            'amount' => 30,
            'date' => now(),
            'status' => ExpenseStatus::Paid,
            'created_by_user_id' => $user->id,
        ]);

        $expense->delete();

        $this->assertSoftDeleted($expense);
        $this->assertNull(Expense::find($expense->id));
        $this->assertNotNull(Expense::withTrashed()->find($expense->id));
    }
}
