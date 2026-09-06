<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceModuleTest extends TestCase
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

    public function test_invoice_can_be_created_with_defaults(): void
    {
        [, $client, $project] = $this->makeProject();

        $invoice = Invoice::create([
            'project_id' => $project->id,
            'client_id' => $client->id,
            'number' => 'INV-001',
            'issue_date' => now(),
            'due_date' => now()->addDays(14),
            'subtotal' => 100,
            'tax' => 18,
            'total' => 118,
            'status' => InvoiceStatus::Issued,
        ]);

        $this->assertDatabaseHas('invoices', ['number' => 'INV-001']);
        $this->assertSame('AZN', $invoice->fresh()->currency);
        $this->assertSame(0.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertTrue($invoice->project->is($project));
        $this->assertTrue($invoice->client->is($client));
    }

    public function test_is_overdue_when_due_date_passed_and_not_fully_paid(): void
    {
        [, $client, $project] = $this->makeProject();

        $overdue = Invoice::create([
            'project_id' => $project->id,
            'client_id' => $client->id,
            'number' => 'INV-OVERDUE',
            'due_date' => now()->subDay(),
            'total' => 100,
            'paid_amount' => 40,
            'status' => InvoiceStatus::PartiallyPaid,
        ]);

        $this->assertTrue($overdue->isOverdue());
    }

    public function test_is_not_overdue_when_fully_paid_or_future_due(): void
    {
        [, $client, $project] = $this->makeProject();

        $fullyPaid = Invoice::create([
            'project_id' => $project->id,
            'client_id' => $client->id,
            'number' => 'INV-PAID',
            'due_date' => now()->subDay(),
            'total' => 100,
            'paid_amount' => 100,
            'status' => InvoiceStatus::Paid,
        ]);

        $futureDue = Invoice::create([
            'project_id' => $project->id,
            'client_id' => $client->id,
            'number' => 'INV-FUTURE',
            'due_date' => now()->addWeek(),
            'total' => 100,
            'paid_amount' => 0,
            'status' => InvoiceStatus::Sent,
        ]);

        $this->assertFalse($fullyPaid->isOverdue());
        $this->assertFalse($futureDue->isOverdue());
    }
}
