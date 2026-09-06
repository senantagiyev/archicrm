<?php

namespace Tests\Feature;

use App\Enums\ChangeRequestStatus;
use App\Enums\SpecificationStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\Design\SpecificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpecChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $user = User::create(['name' => 'U', 'email' => 'u@t.az', 'password' => 'secret123', 'role' => 'designer']);
        $client = Client::create(['name' => 'C', 'status' => 'client']);

        return Project::create([
            'client_id' => $client->id, 'name' => 'P', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);
    }

    public function test_approved_spec_creates_procurement_item(): void
    {
        $project = $this->project();
        $spec = $project->specificationItems()->create([
            'product_name' => 'Divan', 'category' => 'furniture', 'room' => 'Qonaq',
            'client_price' => 850, 'supplier_cost' => 500, 'quantity' => 2,
            'status' => SpecificationStatus::Approved->value,
        ]);

        $procurement = app(SpecificationService::class)->sendToProcurement($spec);

        $this->assertNotNull($procurement->id);
        $this->assertSame('Divan', $procurement->name);
        $this->assertSame($procurement->id, $spec->fresh()->procurement_item_id);
        $this->assertSame(SpecificationStatus::ProcurementReady, $spec->fresh()->status);
    }

    public function test_unapproved_spec_cannot_go_to_procurement(): void
    {
        $project = $this->project();
        $spec = $project->specificationItems()->create([
            'product_name' => 'Stol', 'category' => 'furniture', 'client_price' => 100, 'quantity' => 1,
            'status' => SpecificationStatus::Draft->value,
        ]);

        $this->expectException(\RuntimeException::class);
        app(SpecificationService::class)->sendToProcurement($spec);
    }

    public function test_supplier_cost_hidden_from_array(): void
    {
        $project = $this->project();
        $spec = $project->specificationItems()->create([
            'product_name' => 'X', 'category' => 'decor', 'client_price' => 10, 'supplier_cost' => 5, 'quantity' => 1,
        ]);
        $this->assertArrayNotHasKey('supplier_cost', $spec->fresh()->toArray());
    }

    public function test_change_request_number_and_transitions(): void
    {
        $project = $this->project();
        $cr = $project->changeRequests()->create(['title' => 'Mətbəx dəyişikliyi', 'requested_by' => 'client']);

        $this->assertStringStartsWith('CR-'.$project->id.'-', $cr->number);
        $this->assertSame(ChangeRequestStatus::Draft, $cr->status);

        $cr->transitionTo(ChangeRequestStatus::ImpactAssessment);
        $this->assertSame(ChangeRequestStatus::ImpactAssessment, $cr->fresh()->status);

        // draft→approved illegal
        $this->expectException(\RuntimeException::class);
        $cr->transitionTo(ChangeRequestStatus::Approved);
    }
}
