<?php

namespace Tests\Feature\Scenarios;

use App\Enums\ApprovalStatus;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Finance\ProfitabilityService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Scenario: the procurement side of the money — Xərclər and Satınalma
 * sifarişləri. Both feed numbers the studio pays out on, so the arithmetic has
 * to hold and neither may quietly count the same money twice.
 */
class ProcurementModuleScenarioTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('tedaruk');
    }

    private function inStudio(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    private function supplier(): Supplier
    {
        return $this->inStudio(fn () => Supplier::create([
            'name' => 'Mebel MMC',
            'category' => 'supplier',
            'phone' => '+994501112233',
        ]));
    }

    private function order(array $attributes = []): PurchaseOrder
    {
        return $this->inStudio(fn () => PurchaseOrder::create(array_merge([
            'supplier_id' => $this->supplier()->id,
            'project_id' => $this->studio->project->id,
            'order_date' => today(),
            'items' => [
                ['name' => 'Kreslo', 'qty' => 4, 'price' => 250],
                ['name' => 'Masa', 'qty' => 1, 'price' => 800],
            ],
            'tax' => 100,
            'status' => 'draft',
        ], $attributes)));
    }

    // ------------------------------------------------------- Satınalma sifarişi

    /**
     * 4×250 + 1×800 = 1800. The form lets all three money fields be typed by
     * hand, so nothing stops an order whose total contradicts its own lines.
     */
    public function test_the_order_subtotal_is_computed_from_its_line_items(): void
    {
        $order = $this->order();

        $this->assertSame('1800.00', (string) $order->fresh()->subtotal, 'Ara cəm pozisiyalardan hesablanmır.');
    }

    public function test_the_order_total_is_the_subtotal_plus_tax(): void
    {
        $order = $this->order();

        $this->assertSame('1900.00', (string) $order->fresh()->total, 'Yekun = ara cəm + vergi olmalıdır (1800 + 100).');
    }

    public function test_a_hand_entered_total_cannot_contradict_the_line_items(): void
    {
        // Someone types 50 into "Yekun" for an order of 1800 worth of furniture.
        $order = $this->order(['subtotal' => 50, 'total' => 50]);

        $this->assertSame(
            '1900.00',
            (string) $order->fresh()->total,
            'Əl ilə yazılmış yekun pozisiyaları üstələdi — sifariş öz sətirləri ilə ziddiyyət təşkil edir.',
        );
    }

    public function test_an_order_with_no_items_is_not_forced_to_zero(): void
    {
        // A lump-sum order with no itemisation: the typed subtotal is all there is.
        $order = $this->order(['items' => [], 'subtotal' => 600, 'tax' => 0]);

        $this->assertSame('600.00', (string) $order->fresh()->total, 'Pozisiyasız sifarişdə əl ilə yazılan məbləğ itdi.');
    }

    public function test_a_supplier_with_orders_cannot_be_removed(): void
    {
        $order = $this->order();

        try {
            $order->supplier->delete();

            $this->fail('Sifarişləri olan təchizatçı silindi — sifarişlər sahibsiz qaldı.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('satınalma sifarişləri var', $e->getMessage());
        }

        $this->assertNotNull($order->fresh()->supplier, 'Sifariş təchizatçısını itirdi.');
    }

    public function test_a_delivered_order_cannot_be_pushed_back_to_draft(): void
    {
        $order = $this->order();
        $order->update(['status' => 'ordered']);
        $order->update(['status' => 'received']);

        try {
            $order->update(['status' => 'draft']);

            $this->fail('Qəbul edilmiş sifariş qaralamaya qaytarıldı.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('keçirmək olmaz', $e->getMessage());
        }
    }

    public function test_goods_cannot_be_received_without_ever_being_ordered(): void
    {
        $order = $this->order();

        try {
            $order->update(['status' => 'received']);

            $this->fail('Qaralama sifariş birbaşa «qəbul edilib» statusuna keçdi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('keçirmək olmaz', $e->getMessage());
        }
    }

    public function test_purchase_orders_never_reach_another_studio(): void
    {
        $other = StudioWorld::make('qonsu2');
        $this->order();

        $visible = app(TenantContext::class)
            ->actingAs($other->tenant->id, fn () => PurchaseOrder::count());

        $this->assertSame(0, $visible, 'Başqa studiya bu studiyanın satınalma sifarişini gördü.');
    }

    // -------------------------------------------------------------- Xərclər

    public function test_an_expense_cannot_be_approved_by_the_person_who_filed_it(): void
    {
        $accountant = $this->studio->user('accountant');

        $expense = $this->inStudio(fn () => Expense::create([
            'project_id' => $this->studio->project->id,
            'category' => 'other',
            'vendor' => 'Təchizatçı',
            'amount' => 400,
            'date' => today(),
            'status' => ExpenseStatus::Pending->value,
            'created_by_user_id' => $accountant->id,
        ]));

        try {
            $expense->update([
                'status' => ExpenseStatus::Approved->value,
                'approved_by_user_id' => $accountant->id,
            ]);

            $this->fail('Xərci yazan şəxs onu özü təsdiqlədi — dörd göz prinsipi yoxdur.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('özü təsdiqləyə bilməz', $e->getMessage());
        }

        // Another person approving the same claim must go through.
        $expense->update([
            'status' => ExpenseStatus::Approved->value,
            'approved_by_user_id' => $this->studio->user('owner')->id,
        ]);

        $this->assertSame(ExpenseStatus::Approved, $expense->fresh()->status);
    }

    public function test_a_negative_expense_cannot_be_stored(): void
    {
        $this->expectException(\Throwable::class);

        $this->inStudio(fn () => Expense::create([
            'project_id' => $this->studio->project->id,
            'category' => 'other',
            'vendor' => 'Təchizatçı',
            'amount' => -500,
            'date' => today(),
            'status' => ExpenseStatus::Approved->value,
            'created_by_user_id' => $this->studio->user('accountant')->id,
        ]));
    }

    /**
     * A placed order is money the studio owes a supplier, so it belongs in cost.
     * Before this the module fed no financial figure at all — a 50 000 ₼ order
     * appeared nowhere.
     */
    public function test_a_placed_order_counts_as_project_cost(): void
    {
        $order = $this->order();
        $order->update(['status' => 'ordered']);

        $result = app(ProfitabilityService::class)->forProject($this->studio->project);

        $this->assertSame(1900.0, $result['purchases'], 'Verilmiş sifariş maya dəyərinə düşmür.');
        $this->assertSame(1900.0, $result['cost']);
    }

    public function test_a_draft_or_cancelled_order_is_not_cost(): void
    {
        $this->order(); // stays draft

        $cancelled = $this->order();
        $cancelled->update(['status' => 'cancelled']);

        $result = app(ProfitabilityService::class)->forProject($this->studio->project);

        $this->assertSame(
            0.0,
            $result['purchases'],
            'Qaralama və ya ləğv edilmiş sifariş maya dəyərinə düşdü — heç kim onu sifariş verməyib.',
        );
    }

    public function test_the_portfolio_cost_includes_placed_orders(): void
    {
        $order = $this->order();
        $order->update(['status' => 'ordered']);
        $order->update(['status' => 'received']);

        $portfolio = $this->inStudio(fn () => app(ProfitabilityService::class)->portfolio());

        $this->assertSame(1900.0, $portfolio['cost'], 'Portfel maya dəyəri satınalma sifarişlərini saymır.');
    }

    /**
     * Procurement billed to the client with nothing on the paying side would show
     * as pure profit. The report cannot invent the cost, but it must flag that it
     * is missing rather than present the margin as real.
     */
    public function test_procurement_billed_with_no_recorded_cost_is_flagged(): void
    {
        $item = $this->studio->procurementItem;
        $item->forceFill(['approval_status' => ApprovalStatus::Approved->value])->save();

        $result = app(ProfitabilityService::class)->forProject($this->studio->project->fresh());

        $this->assertGreaterThan(
            0,
            $result['uncosted_procurement'],
            'Müştəriyə fakturalanmış komplektasiyanın maya dəyəri yoxdur, amma hesabat bunu bildirmir.',
        );
    }

    public function test_the_flag_clears_once_the_purchase_is_recorded(): void
    {
        $item = $this->studio->procurementItem;
        $item->forceFill(['approval_status' => ApprovalStatus::Approved->value])->save();

        $order = $this->order();
        $order->update(['status' => 'ordered']);

        $result = app(ProfitabilityService::class)->forProject($this->studio->project->fresh());

        $this->assertSame(0.0, $result['uncosted_procurement'], 'Alış qeyd olunduğu halda xəbərdarlıq qalır.');
    }

    public function test_expenses_of_another_studio_never_reach_this_cost_figure(): void
    {
        $other = StudioWorld::make('qonsu3');

        app(TenantContext::class)->actingAs($other->tenant->id, fn () => Expense::create([
            'project_id' => $other->project->id,
            'category' => 'other',
            'vendor' => 'Yad',
            'amount' => 12345,
            'date' => today(),
            'status' => ExpenseStatus::Paid->value,
            'created_by_user_id' => $other->user('accountant')->id,
        ]));

        $portfolio = $this->inStudio(fn () => app(ProfitabilityService::class)->portfolio());

        $this->assertSame(0.0, $portfolio['cost'], 'Portfel maya dəyərinə başqa studiyanın xərci düşdü.');
    }
}
