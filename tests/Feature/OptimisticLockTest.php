<?php

namespace Tests\Feature;

use App\Exceptions\RowVersionConflictException;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptimisticLockTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        $user = User::create(['name' => 'M', 'email' => 'm@test.az', 'password' => 'secret123', 'role' => 'owner']);
        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);
        $project = Project::create([
            'client_id' => $client->id, 'name' => 'L', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);

        return Invoice::create([
            'project_id' => $project->id, 'client_id' => $client->id, 'number' => 'INV-1',
            'total' => 100, 'status' => 'draft',
        ]);
    }

    public function test_version_starts_at_one_and_increments_on_update(): void
    {
        $invoice = $this->invoice();
        $this->assertSame(1, (int) $invoice->row_version);

        $invoice->update(['total' => 200]);
        $this->assertSame(2, (int) $invoice->fresh()->row_version);
    }

    public function test_concurrent_stale_save_is_rejected(): void
    {
        $invoice = $this->invoice();

        // Two users load the same row.
        $userA = Invoice::find($invoice->id);
        $userB = Invoice::find($invoice->id);

        // A saves first — succeeds, bumps the version to 2.
        $userA->update(['total' => 300]);
        $this->assertSame(2, (int) $invoice->fresh()->row_version);

        // B still holds version 1 — saving must conflict, not clobber A's change.
        $this->expectException(RowVersionConflictException::class);
        $userB->update(['total' => 999]);
    }

    public function test_conflict_carries_both_versions(): void
    {
        $invoice = $this->invoice();
        $userA = Invoice::find($invoice->id);
        $userB = Invoice::find($invoice->id);
        $userA->update(['total' => 300]);

        try {
            $userB->update(['total' => 999]);
            $this->fail('Expected RowVersionConflictException.');
        } catch (RowVersionConflictException $e) {
            $this->assertSame(2, $e->currentVersion);
            $this->assertSame(1, $e->yourVersion);
        }

        // B's clobbering write never landed.
        $this->assertSame('300.00', $invoice->fresh()->total);
    }
}
