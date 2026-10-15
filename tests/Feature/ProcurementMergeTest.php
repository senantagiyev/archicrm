<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentStatus;
use App\Enums\SelectionStatus;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\ProcurementItemsRelationManager;
use App\Models\Payment;
use App\Models\ProcurementItem;
use App\Models\PurchaseOrder;
use App\Models\SpecificationItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\ProcurementValueService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Komplektasiya = Spesifikasiyalar + Komplektasiya (TZ «Layihə naviqasiyası» §7,
 * qəbul meyarı 18): köçürmə, üç ayrı dəyər göstəricisi və onların mənbəyi.
 */
class ProcurementMergeTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_15_300100_merge_specification_items_into_procurement_items.php';

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('merge');
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    private function asStaff(string $role): User
    {
        $user = $this->studio->user($role);

        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->studio->tenant->id);
        AccessMatrix::flushCache();

        return $user;
    }

    private function runMergeMigration(): void
    {
        (include database_path(self::MIGRATION))->up();
    }

    private function relationManager()
    {
        return Livewire::test(ProcurementItemsRelationManager::class, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
        ]);
    }

    private function spec(array $attributes): SpecificationItem
    {
        return $this->inTenant(fn () => SpecificationItem::create($attributes + [
            'project_id' => $this->studio->project->id,
            'category' => 'furniture',
            'quantity' => 1,
            'client_price' => 0,
            'supplier_cost' => 0,
            'status' => 'draft',
        ]));
    }

    // ═══════════════════════ KÖÇÜRMƏ ═══════════════════════

    public function test_unlinked_specifications_become_procurement_items_and_the_migration_is_idempotent(): void
    {
        $approved = $this->spec([
            'product_name' => 'Kreslo Milano',
            'brand' => 'Poltrona',
            'model' => 'M-2',
            'category' => 'lighting',
            'room' => 'Qonaq otağı',
            'unit' => 'ədəd',
            'client_price' => 500,
            'supplier_cost' => 320,
            'quantity' => 2,
            'supplier' => 'Mebel MMC',
            'link' => 'https://example.test/kreslo',
            'image' => 'specs/kreslo.jpg',
            'status' => 'approved',
        ]);
        $rejected = $this->spec(['product_name' => 'Alternativ kreslo', 'status' => 'rejected']);

        $existingItemCount = ProcurementItem::withoutGlobalScopes()->count();
        $specCount = SpecificationItem::withoutGlobalScopes()->count();

        $this->runMergeMigration();
        $this->runMergeMigration();

        $this->assertSame($existingItemCount + 2, ProcurementItem::withoutGlobalScopes()->count(), 'Təkrar işə salma dublikat pozisiya yaratdı.');
        $this->assertSame($specCount, SpecificationItem::withoutGlobalScopes()->count(), 'Spesifikasiya sətirləri silinməməlidir.');

        $approved->refresh();
        $this->assertNotNull($approved->procurement_item_id);

        $item = ProcurementItem::withoutGlobalScopes()->findOrFail($approved->procurement_item_id);
        $this->assertSame($this->studio->tenant->id, $item->tenant_id);
        $this->assertSame('Kreslo Milano', $item->name);
        $this->assertSame('Poltrona', $item->brand);
        $this->assertSame('M-2', $item->model);
        $this->assertSame('İşıqlandırma', $item->category);
        $this->assertSame('ədəd', $item->unit);
        $this->assertSame('500.00', $item->price);
        $this->assertSame('320.00', $item->supplier_cost);
        $this->assertSame('1000.00', $item->total);
        $this->assertSame('Mebel MMC', $item->store);
        $this->assertSame('specs/kreslo.jpg', $item->photo_path);
        $this->assertSame(SelectionStatus::Selected, $item->selection_status);
        // Spesifikasiyanın «Təsdiqlənib»-i daxili seçimdir — müştəri razılaşması
        // kimi köçsəydi, layihənin borcu səssizcə artardı.
        $this->assertSame(ApprovalStatus::Draft, $item->approval_status);
        $this->assertFalse($item->visible_to_client);

        $this->assertSame(
            SelectionStatus::Rejected,
            ProcurementItem::withoutGlobalScopes()->findOrFail($rejected->refresh()->procurement_item_id)->selection_status,
        );
    }

    public function test_linked_specifications_only_fill_missing_fields(): void
    {
        $item = $this->inTenant(fn () => ProcurementItem::create([
            'project_id' => $this->studio->project->id,
            'name' => 'Əl ilə adlandırılmış divan',
            'price' => 999,
            'qty' => 1,
            'store' => 'Öz təchizatçımız',
        ]));

        $this->spec([
            'product_name' => 'Divan',
            'brand' => 'Natuzzi',
            'unit' => 'ədəd',
            'supplier' => 'Spesifikasiyadakı təchizatçı',
            'client_price' => 1200,
            'status' => 'procurement_ready',
            'procurement_item_id' => $item->id,
        ]);

        $this->runMergeMigration();

        $item = ProcurementItem::withoutGlobalScopes()->findOrFail($item->id);
        $this->assertSame('Natuzzi', $item->brand, 'Boş brend doldurulmadı.');
        $this->assertSame('ədəd', $item->unit);
        $this->assertSame('Əl ilə adlandırılmış divan', $item->name, 'Dolu ad üzərinə yazıldı.');
        $this->assertSame('Öz təchizatçımız', $item->store, 'Dolu təchizatçı üzərinə yazıldı.');
        $this->assertSame('999.00', $item->price, 'Plan qiyməti köçürmədə dəyişməməlidir.');
    }

    public function test_a_sent_specification_whose_item_was_deleted_is_not_resurrected(): void
    {
        $orphan = $this->spec(['product_name' => 'Silinmiş pozisiya', 'status' => 'procurement_ready']);
        $before = ProcurementItem::withoutGlobalScopes()->count();

        $this->runMergeMigration();

        $this->assertSame($before, ProcurementItem::withoutGlobalScopes()->count());
        $this->assertNull($orphan->refresh()->procurement_item_id);
        $this->assertDatabaseHas('specification_items', ['id' => $orphan->id]);
    }

    // ═══════════════════════ ÜÇ GÖSTƏRİCİ ═══════════════════════

    public function test_planned_ordered_and_paid_values_are_independent(): void
    {
        $project = $this->studio->project;

        [$item, $order, $payment] = $this->inTenant(function () use ($project) {
            $item = ProcurementItem::create(['project_id' => $project->id, 'name' => 'Çilçıraq', 'price' => 100, 'qty' => 2]);
            $supplier = Supplier::create(['name' => 'İşıq MMC']);

            $order = PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'project_id' => $project->id,
                'status' => 'ordered',
                'items' => [
                    ['procurement_item_id' => $item->id, 'name' => 'Çilçıraq', 'qty' => 2, 'price' => 80],
                    ['name' => 'Əlaqəsiz sətir', 'qty' => 1, 'price' => 500],
                ],
            ]);

            // Qaralama sifariş hələ təsdiqlənmiş dəyər deyil.
            PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'project_id' => $project->id,
                'status' => 'draft',
                'items' => [['procurement_item_id' => $item->id, 'name' => 'Çilçıraq', 'qty' => 5, 'price' => 80]],
            ]);

            // Yad layihənin sifarişi bu pozisiyaya işarə etsə də sayılmır.
            PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'project_id' => $this->studio->otherProject->id,
                'status' => 'ordered',
                'items' => [['procurement_item_id' => $item->id, 'name' => 'Çilçıraq', 'qty' => 9, 'price' => 80]],
            ]);

            $payment = Payment::create([
                'project_id' => $project->id, 'procurement_item_id' => $item->id,
                'title' => 'Çilçıraq ödənişi', 'amount' => 150, 'status' => 'paid', 'paid_at' => now(),
            ]);
            Payment::create([
                'project_id' => $project->id, 'procurement_item_id' => $item->id,
                'title' => 'Qalıq', 'amount' => 50, 'status' => 'pending',
            ]);

            return [$item, $order, $payment];
        });

        $values = fn () => (new ProcurementValueService)->summary($item->fresh());

        $this->assertSame(200.0, $values()['planned']);
        $this->assertSame(160.0, $values()['ordered']);
        $this->assertSame(150.0, $values()['paid']);
        $this->assertSame(150.0, $item->fresh()->paidAmount());

        // Plan qiyməti dəyişir → sifariş və ödəniş yerində qalır.
        $this->inTenant(fn () => $item->fresh()->update(['price' => 120]));
        $this->assertSame([240.0, 160.0, 150.0], [$values()['planned'], $values()['ordered'], $values()['paid']]);

        // Sifariş sətri dəyişir → plan qiyməti yerində qalır.
        $this->inTenant(fn () => $order->fresh()->update(['items' => [
            ['procurement_item_id' => $item->id, 'name' => 'Çilçıraq', 'qty' => 2, 'price' => 90],
        ]]));
        $this->assertSame([240.0, 180.0, 150.0], [$values()['planned'], $values()['ordered'], $values()['paid']]);
        $this->assertSame('120.00', $item->fresh()->price);

        // Ödəniş geri alınır → plan və sifariş, həm də əl ilə qoyulan işarə yerində qalır.
        $this->inTenant(fn () => $payment->fresh()->update(['status' => PaymentStatus::Pending]));
        $this->assertSame([240.0, 180.0, 0.0], [$values()['planned'], $values()['ordered'], $values()['paid']]);
        $this->assertFalse($item->fresh()->paid);
    }

    public function test_an_item_without_a_confirmed_order_reports_no_order_value_rather_than_zero(): void
    {
        $this->assertNull((new ProcurementValueService)->orderedTotal($this->studio->procurementItem));
    }

    public function test_a_payment_cannot_be_linked_to_another_projects_item(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->inTenant(fn () => Payment::create([
            'project_id' => $this->studio->otherProject->id,
            'procurement_item_id' => $this->studio->procurementItem->id,
            'title' => 'Yanlış əlaqə', 'amount' => 10, 'status' => 'pending',
        ]));
    }

    public function test_an_approved_item_with_an_accounting_payment_is_deletion_locked(): void
    {
        $item = $this->studio->procurementItem;

        $this->inTenant(function () use ($item): void {
            $item->forceFill(['approval_status' => ApprovalStatus::Approved])->save();
            Payment::create([
                'project_id' => $item->project_id, 'procurement_item_id' => $item->id,
                'title' => 'Divan', 'amount' => 1200, 'status' => 'paid', 'paid_at' => now(),
            ]);
        });

        $this->assertFalse($item->fresh()->paid, 'Əl ilə qoyulan işarə avtomatik dəyişməməlidir.');
        $this->assertTrue($item->fresh()->isDeletionLocked());
    }

    // ═══════════════════════ PANEL: KOMPLEKTASİYA TABI ═══════════════════════

    public function test_the_tab_shows_each_value_with_its_source(): void
    {
        $item = $this->studio->procurementItem;

        $order = $this->inTenant(function () use ($item) {
            $order = PurchaseOrder::create([
                'supplier_id' => Supplier::create(['name' => 'Divan MMC'])->id,
                'project_id' => $item->project_id,
                'status' => 'ordered',
                'items' => [['procurement_item_id' => $item->id, 'name' => 'Divan', 'qty' => 1, 'price' => 900]],
            ]);
            Payment::create([
                'project_id' => $item->project_id, 'procurement_item_id' => $item->id,
                'title' => 'Divan avansı', 'amount' => 400, 'status' => 'paid', 'paid_at' => now(),
            ]);

            return $order;
        });

        $this->asStaff('owner');

        $this->relationManager()
            ->assertTableColumnVisible('paid_total')
            ->assertSee('Mənbə: komplektasiya')
            ->assertSee('Mənbə: satınalma sifarişi №'.$order->id)
            ->assertSee('Mənbə: mühasibatlıq · 1 ödəniş qeydi')
            ->assertTableColumnStateSet('ordered_total', 900.0, $item)
            ->assertTableColumnStateSet('paid_total', 400.0, $item);
    }

    public function test_payment_amounts_are_hidden_from_a_role_without_accounting_access(): void
    {
        // Təchizatçı: Komplektasiya = Tam, Ödənişlər = Yoxdur.
        $this->asStaff('procurement');

        $this->relationManager()
            ->assertSuccessful()
            ->assertTableColumnVisible('ordered_total')
            ->assertTableColumnHidden('paid_total');
    }

    public function test_photos_and_technical_documents_are_stored_on_the_position(): void
    {
        Storage::fake('public');

        $this->asStaff('procurement');

        $this->relationManager()
            ->mountTableAction('create')
            ->setTableActionData([
                'name' => 'Kran Grohe',
                'brand' => 'Grohe',
                'characteristics' => 'Xrom, 1/2"',
                'unit' => 'ədəd',
                'price' => 250,
                'qty' => 2,
                'selection_status' => SelectionStatus::Proposed->value,
                'attachments' => [UploadedFile::fake()->image('texniki-pasport.jpg', 20, 20)],
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $item = $this->inTenant(fn () => ProcurementItem::where('name', 'Kran Grohe')->firstOrFail());

        $this->assertSame('Grohe', $item->brand);
        $this->assertSame(SelectionStatus::Proposed, $item->selection_status);
        $this->assertCount(1, $item->attachments);
        Storage::disk('public')->assertExists($item->attachments[0]);
    }

    public function test_a_position_links_to_a_smeta_line_instead_of_copying_it(): void
    {
        $this->asStaff('project_manager');

        $item = $this->studio->procurementItem;
        $line = $this->studio->budgetLine;

        $this->relationManager()
            ->callTableAction('edit', $item, data: ['budget_line_id' => $line->id])
            ->assertHasNoTableActionErrors();

        $item = $item->fresh();
        $this->assertSame($line->id, $item->budget_line_id);
        $this->assertSame('1200.00', $item->price, 'Smeta əlaqəsi plan qiymətini dəyişməməlidir.');
        $this->assertSame(1, $line->procurementItems()->count());

        // Başqa layihənin smeta sətri seçilə bilməz.
        $foreignLine = $this->inTenant(fn () => $this->studio->otherProject->budgetLines()->create([
            'work_type' => 'Yad sətir', 'qty' => 1, 'work_price' => 1, 'material_price' => 0,
        ]));

        $this->relationManager()
            ->callTableAction('edit', $item, data: ['budget_line_id' => $foreignLine->id])
            ->assertHasTableActionErrors(['budget_line_id']);

        $this->assertSame($line->id, DB::table('procurement_items')->where('id', $item->id)->value('budget_line_id'));
    }
}
