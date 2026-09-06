<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_can_be_created(): void
    {
        $supplier = Supplier::create([
            'name' => 'Mebel MMC',
            'category' => 'Mebel',
            'contact' => 'Əli Vəliyev',
            'phone' => '+994501234567',
            'email' => 'info@mebel.az',
            'website' => 'https://mebel.az',
            'address' => 'Bakı',
            'payment_terms' => '30 gün',
            'rating' => 5,
            'notes' => 'Etibarlı təchizatçı',
        ]);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Mebel MMC',
            'category' => 'Mebel',
            'rating' => 5,
        ]);

        $this->assertSame(5, $supplier->fresh()->rating);
    }

    public function test_purchase_order_belongs_to_supplier_and_project(): void
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

        $supplier = Supplier::create(['name' => 'Mebel MMC', 'category' => 'Mebel']);

        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'project_id' => $project->id,
            'order_date' => now(),
            'items' => [
                ['name' => 'Divan', 'qty' => 2, 'price' => 500],
                ['name' => 'Masa', 'qty' => 1, 'price' => 300],
            ],
            'subtotal' => 1300,
            'tax' => 234,
            'total' => 1534,
            'payment_terms' => '30 gün',
            'expected_delivery' => now()->addWeek(),
            'status' => PurchaseOrderStatus::Ordered,
        ]);

        $fresh = $order->fresh();

        $this->assertSame($supplier->id, $fresh->supplier->id);
        $this->assertSame($project->id, $fresh->project->id);
        $this->assertIsArray($fresh->items);
        $this->assertCount(2, $fresh->items);
        $this->assertSame('Divan', $fresh->items[0]['name']);
        $this->assertSame(PurchaseOrderStatus::Ordered, $fresh->status);
        $this->assertSame('1534.00', $fresh->total);

        $this->assertCount(1, $supplier->purchaseOrders);
    }

    public function test_purchase_order_project_is_nullable(): void
    {
        $supplier = Supplier::create(['name' => 'Kətan MMC']);

        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'items' => [],
            'status' => PurchaseOrderStatus::Draft,
        ]);

        $this->assertNull($order->fresh()->project);
        $this->assertSame($supplier->id, $order->fresh()->supplier->id);
    }
}
