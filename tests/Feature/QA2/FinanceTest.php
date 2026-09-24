<?php

namespace Tests\Feature\QA2;

use App\Enums\AccessLevel;
use App\Enums\ApprovalStatus;
use App\Enums\Domain;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseStatus;
use App\Filament\Pages\Profitability;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrderResource\Pages\CreatePurchaseOrder;
use App\Filament\Widgets\CashForecastWidget;
use App\Filament\Widgets\PortfolioFinanceStats;
use App\Filament\Widgets\ProfitabilityWidget;
use App\Models\BudgetLine;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Finance\ProfitabilityService;
use App\Services\Finance\ProjectFinanceService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA2 — MALİYYƏ: rentabellik hesabatının ARİFMETİKASI mühakimə altındadır.
 *
 * Sahibkarın tələbi: «bu modulun məqsədi hesabat verməkdir — hesabat DOĞRU
 * verirmi?» Ona görə burada hər rəqəm testin içində ƏL İLƏ hesablanır və
 * qəpiyinə qədər tutuşdurulur; «səhifə açılır» tipli yoxlama kifayət deyil.
 *
 * Mövcud `QA/FinanceQaTest` və `Fix/InvoiceIntegrityFixTest` bazanı tutur; bu
 * fayl onların BOŞ buraxdığı yerlərə baxır: borcun hadisə ilə yenilənməsi
 * (ödəniş redaktəsi/silinməsi, razılaşmanın geri alınması), fakturanın
 * silinməsi, kateqoriya/təchizatçı cəmləri, mənfi müddətli vaxt qeydi,
 * vidjet-vidjet studiya izolyasiyası.
 */
class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alfa;

    private StudioWorld $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = StudioWorld::make('alpha');
        $this->beta = StudioWorld::make('beta');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    // =====================================================================
    // Köməkçilər
    // =====================================================================

    private function inTenant(StudioWorld $world, callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($world->tenant->id, $callback);
    }

    private function asStaff(StudioWorld $world, string $role): User
    {
        $user = $world->user($role);
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($world->tenant->id);
        AccessMatrix::flushCache();

        return $user;
    }

    /**
     * HƏR dəfə TƏZƏ servis: `ProfitabilityService` layihə üzrə nəticəni
     * memoizasiya edir, ona görə köhnə instansiya dəyişiklikdən sonra keçmiş
     * rəqəmi qaytarır və test yalan yerə yaşıl olur.
     */
    private function svc(): ProfitabilityService
    {
        return new ProfitabilityService;
    }

    /** StudioWorld-ün hazır 500 ₼ avansı və 700 ₼ smeta sətri cəmləri çaşdırır — hər ssenari ÖZ layihəsini qurur. */
    private function project(StudioWorld $world, float $budgetPlan = 0): Project
    {
        return $this->inTenant($world, fn () => Project::create([
            'client_id' => $world->client->id,
            'name' => 'QA2 maliyyə '.uniqid(),
            'type' => 'apartment',
            'status' => ProjectStatus::Active->value,
            'budget_plan' => $budgetPlan,
        ]));
    }

    private function pay(Project $project, float $amount, string $status = 'paid', ?string $dueDate = null): Payment
    {
        return $this->inTenant($this->worldOf($project), fn () => $project->payments()->create([
            'title' => 'Ödəniş',
            'amount' => $amount,
            'status' => $status,
            'paid_at' => $status === PaymentStatus::Paid->value ? now() : null,
            'due_date' => $dueDate,
        ]));
    }

    private function expense(Project $project, float $amount, string $category = 'material', string $status = 'approved', string $currency = 'AZN'): Expense
    {
        return $this->inTenant($this->worldOf($project), fn () => $project->expenses()->create([
            'category' => $category,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'date' => now(),
        ]));
    }

    /** Vaxt qeydi: tarif AÇIQ verilir, çünki StudioWorld işçilərinin `hourly_internal_cost` dəyəri 0-dır. */
    private function hours(Project $project, int $minutes, float $rate, ?User $user = null): TimeEntry
    {
        $world = $this->worldOf($project);
        $user ??= $world->user('designer');

        return $this->inTenant($world, fn () => TimeEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'duration_minutes' => $minutes,
            'hourly_cost_snapshot' => $rate,
            'source' => 'manual',
        ]));
    }

    /** @param  array<int, array{name: string, qty: float, price: float}>  $items */
    private function purchaseOrder(
        ?Project $project,
        float $subtotal,
        float $tax = 0,
        string $status = 'ordered',
        array $items = [],
        ?StudioWorld $world = null,
        ?Supplier $supplier = null,
    ): PurchaseOrder {
        $world ??= $project ? $this->worldOf($project) : $this->alfa;

        return $this->inTenant($world, function () use ($project, $subtotal, $tax, $status, $items, $supplier) {
            $supplier ??= Supplier::create(['name' => 'Təchizatçı '.uniqid()]);

            return PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'project_id' => $project?->id,
                'order_date' => now(),
                'items' => $items,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'status' => $status,
            ]);
        });
    }

    private function invoice(Project $project, float $subtotal, float $tax = 0, float $paid = 0, string $status = 'sent', string $currency = 'AZN', ?string $dueDate = null): Invoice
    {
        $world = $this->worldOf($project);

        return $this->inTenant($world, fn () => Invoice::create([
            'project_id' => $project->id,
            'client_id' => $project->client_id,
            'number' => 'QA2-'.uniqid(),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'paid_amount' => $paid,
            'status' => $status,
            'currency' => $currency,
            'issue_date' => now(),
            'due_date' => $dueDate,
        ]));
    }

    /** Razılaşdırılmış smeta sətri — `approval_status` qəsdən fillable deyil (audit HIGH-2). */
    private function approvedBudgetLine(Project $project, float $total): BudgetLine
    {
        return $this->inTenant($this->worldOf($project), function () use ($project, $total) {
            $line = $project->budgetLines()->create([
                'work_type' => 'İş', 'unit' => 'm2', 'qty' => 1,
                'work_price' => $total, 'material_price' => 0, 'position' => 1,
                'visible_to_client' => true,
            ]);

            $line->forceFill(['approval_status' => ApprovalStatus::Approved->value])->save();

            return $line;
        });
    }

    private function worldOf(Project $project): StudioWorld
    {
        return (int) $project->tenant_id === (int) $this->beta->tenant->id ? $this->beta : $this->alfa;
    }

    private function debtOf(Project $project): float
    {
        return (float) $project->fresh()->debt;
    }

    // =====================================================================
    // A. RENTABELLİK HESABATININ ARİFMETİKASI
    // =====================================================================

    /**
     * Sahibkarın ssenarisi, bir layihə, bütün rəqəmlər əl ilə:
     * plan smeta 10 000, razılaşdırılmış iş 8 000, ödənilib 5 000,
     * xərclər 1 200, 10 saat × 25 ₼, satınalma 900.
     *
     * Gəlir yalnız `paid` ödənişlərdir; maya dəyəri = əmək + xərclər +
     * satınalma sifarişləri. Hər sətir ayrıca yoxlanılır ki, cəm təsadüfən
     * düz çıxdıqda səhv gizlənməsin.
     */
    public function test_the_profitability_report_adds_up_to_the_cent(): void
    {
        $project = $this->project($this->alfa, budgetPlan: 10000);

        $this->approvedBudgetLine($project, 8000);      // razılaşdırılmış iş
        $this->inTenant($this->alfa, fn () => $project->budgetLines()->create([
            'work_type' => 'Razılaşdırılmamış', 'unit' => 'm2', 'qty' => 1,
            'work_price' => 2000, 'material_price' => 0, 'position' => 2,
        ]));                                            // 10 000 planın qalan 2 000-i

        $this->pay($project, 5000);                     // yığılmış gəlir
        $this->pay($project, 2000, 'pending');          // hələ gəlir deyil
        $this->expense($project, 1200);                 // xərc
        $this->hours($project, 600, 25);                // 10 saat × 25 = 250
        $this->purchaseOrder($project, 900);            // satınalma 900

        $r = $this->svc()->forProject($project->fresh());

        $this->assertSame(5000.00, $r['revenue'], 'Gəlir yalnız təsdiqlənmiş ödənişlərdən yığılmalıdır.');
        $this->assertSame(250.00, $r['labor_cost'], '10 saat × 25 ₼ = 250 ₼ əmək dəyəri.');
        $this->assertSame(1200.00, $r['expenses']);
        $this->assertSame(900.00, $r['purchases']);
        $this->assertSame(2350.00, $r['cost'], '250 + 1200 + 900 = 2350.');
        $this->assertSame(2650.00, $r['gross_profit'], '5000 − 2350 = 2650.');
        $this->assertSame(53.0, $r['margin'], '2650 / 5000 × 100 = 53.0%.');
        $this->assertSame(10000.00, $r['projected_revenue'], 'Proqnoz gəlir = plan büdcə.');

        // Maya dəyəri tam yazılıb — hesabat «natamam» ulduzu qoymamalıdır.
        $this->assertSame(0, $r['uncosted_minutes']);
        $this->assertSame(0.0, $r['uncosted_procurement']);

        // Borc DÜSTURU: razılaşdırılmış smeta + razılaşdırılmış komplektasiya − ödənilib.
        $this->assertSame(3000.00, $this->debtOf($project), '8000 − 5000 = 3000; razılaşdırılmamış 2000 borc deyil.');
    }

    /** Yuvarlaqlaşdırma: 50 dəqiqə × 37 ₼/saat = 30.8333… → 30.83, marja bir onluq rəqəmə. */
    public function test_money_rounds_to_two_decimals_and_margin_to_one(): void
    {
        $project = $this->project($this->alfa);
        $this->pay($project, 1000);
        $this->hours($project, 50, 37);

        $r = $this->svc()->forProject($project->fresh());

        $this->assertSame(30.83, $r['labor_cost'], '1850 / 60 = 30.8333… → 30.83.');
        $this->assertSame(969.17, $r['gross_profit'], '1000 − 30.83 = 969.17.');
        $this->assertSame(96.9, $r['margin'], '969.17 / 1000 × 100 = 96.917 → 96.9.');
    }

    /** Sıfıra bölmə: gəlirsiz layihənin marjası 0% DEYİL — ölçmək üçün baza yoxdur. */
    public function test_a_project_with_no_revenue_has_a_null_margin_not_zero(): void
    {
        $project = $this->project($this->alfa);
        $this->expense($project, 750);

        $r = $this->svc()->forProject($project->fresh());

        $this->assertSame(0.00, $r['revenue']);
        $this->assertSame(-750.00, $r['gross_profit']);
        $this->assertNull($r['margin'], '0% «zərərsizlik həddi» kimi oxunur — gəlir yoxdursa marja «—» olmalıdır.');
        $this->assertNull(ProfitabilityService::margin(0.0, -750.0));
        $this->assertNull(ProfitabilityService::margin(0.0, 0.0));
    }

    /** Zərər MƏNFİ göstərilir — modulun işarəsi (abs) deyil. */
    public function test_a_loss_keeps_its_minus_sign_everywhere(): void
    {
        $project = $this->project($this->alfa);
        $this->pay($project, 1000);
        $this->expense($project, 1500);

        $r = $this->svc()->forProject($project->fresh());

        $this->assertSame(-500.00, $r['gross_profit']);
        $this->assertSame(-50.0, $r['margin'], 'Marja −50.0 olmalıdır; 50.0 zərəri mənfəət kimi oxudur.');
        $this->assertLessThan(0, $r['margin']);

        // Vidjet sətri də mənfini mənfi kimi verir (rəng + dəyər).
        $this->asStaff($this->alfa, 'owner');
        $html = Livewire::test(ProfitabilityWidget::class)->html();
        $this->assertStringContainsStringQuietly('-50', $html, 'Rentabellik cədvəli zərərli layihənin marjasını mənfi göstərmir.');
    }

    // =====================================================================
    // B. projects.debt — İŞARƏLİ VƏ HƏR HADİSƏDƏ YENİLƏNƏN
    // =====================================================================

    /** Ödənişin MƏBLƏĞİ redaktə olunanda borc dərhal yenilənməlidir (observer). */
    public function test_editing_a_payment_amount_recalculates_the_debt(): void
    {
        $project = $this->project($this->alfa);
        $this->approvedBudgetLine($project, 1000);
        $payment = $this->pay($project, 400);

        $this->assertSame(600.00, $this->debtOf($project), '1000 − 400 = 600.');

        $this->inTenant($this->alfa, fn () => $payment->update(['amount' => 900]));

        $this->assertSame(100.00, $this->debtOf($project), '1000 − 900 = 100; redaktə borcu yeniləmirsə rəqəm 600 qalır.');
    }

    /** Ödəniş SİLİNƏNDƏ borc geri qalxmalıdır. */
    public function test_deleting_a_payment_restores_the_debt(): void
    {
        $project = $this->project($this->alfa);
        $this->approvedBudgetLine($project, 1000);
        $payment = $this->pay($project, 400);

        $this->assertSame(600.00, $this->debtOf($project));

        $this->inTenant($this->alfa, fn () => $payment->delete());

        $this->assertSame(1000.00, $this->debtOf($project), 'Ödəniş silindi — borc tam məbləğə qayıtmalıdır.');
    }

    /** Status `pending` → `paid` → `pending`: yalnız təsdiqlənmiş ödəniş borcu azaldır. */
    public function test_a_payment_status_change_moves_the_debt_both_ways(): void
    {
        $project = $this->project($this->alfa);
        $this->approvedBudgetLine($project, 1000);
        $payment = $this->pay($project, 400, 'pending');

        $this->assertSame(1000.00, $this->debtOf($project), 'Gözlənilən ödəniş hələ pul deyil.');

        $this->inTenant($this->alfa, fn () => $payment->update(['status' => PaymentStatus::Paid->value, 'paid_at' => now()]));
        $this->assertSame(600.00, $this->debtOf($project));

        // Səhv yazılış geri alınır — borc bərpa olunur.
        $this->inTenant($this->alfa, fn () => $payment->update(['status' => PaymentStatus::Pending->value, 'paid_at' => null]));
        $this->assertSame(1000.00, $this->debtOf($project), 'Ödəniş geri götürüldü — borc yenidən 1000 olmalıdır.');
    }

    /** Razılaşma GERİ ALINANDA (approved → rejected) smeta borcdan çıxır. */
    public function test_unapproving_an_estimate_line_removes_it_from_the_debt(): void
    {
        $project = $this->project($this->alfa);
        $line = $this->approvedBudgetLine($project, 1000);
        $this->pay($project, 400);

        $this->assertSame(600.00, $this->debtOf($project));

        $this->inTenant($this->alfa, fn () => $line->forceFill(['approval_status' => ApprovalStatus::Rejected->value])->save());

        // Razılaşdırılmış iş qalmadı, amma 400 ₼ kassada var → avans.
        $this->assertSame(-400.00, $this->debtOf($project), 'Rədd edilmiş sətir borcda qalmamalıdır; nəticə avansdır (−400).');
    }

    /** Komplektasiya razılaşdırılanda borc artır, ləğv ediləndə çıxır. */
    public function test_approved_procurement_enters_the_debt_and_leaves_it_when_cancelled(): void
    {
        $project = $this->project($this->alfa);
        $item = $this->inTenant($this->alfa, fn () => $project->procurementItems()->create([
            'name' => 'Divan', 'qty' => 2, 'price' => 300, 'purchase_status' => PurchaseStatus::Planned->value,
        ]));

        $this->assertSame('600.00', (string) $item->fresh()->total, '2 × 300 = 600.');
        $this->assertSame(0.00, $this->debtOf($project), 'Razılaşdırılmamış komplektasiya borc deyil.');

        $this->inTenant($this->alfa, fn () => $item->forceFill(['approval_status' => ApprovalStatus::Approved->value])->save());
        $this->assertSame(600.00, $this->debtOf($project));

        $this->inTenant($this->alfa, fn () => $item->update(['purchase_status' => PurchaseStatus::Cancelled->value]));
        $this->assertSame(0.00, $this->debtOf($project), 'Ləğv edilmiş pozisiya müştəriyə borc yazmır.');
    }

    /** Avans MƏNFİ borc verir və bir layihənin avansı digərinin real borcunu YEYƏ BİLMƏZ. */
    public function test_one_projects_advance_never_cancels_another_projects_real_debt(): void
    {
        $advanced = $this->project($this->alfa);
        $this->pay($advanced, 222);                                  // razılaşdırılmış iş yoxdur → avans

        $owing = $this->project($this->alfa);
        $this->approvedBudgetLine($owing, 1000);                     // ödəniş yoxdur → real borc

        $this->assertSame(-222.00, $this->debtOf($advanced), 'Sütun işarəni saxlayır — mühasibat üçün doğrudur.');
        $this->assertSame(1000.00, $this->debtOf($owing));

        $this->asStaff($this->alfa, 'owner');

        $studioDebt = (float) Project::query()
            ->whereNotIn('status', [ProjectStatus::Archived->value])
            ->where('debt', '>', 0)
            ->sum('debt');

        $this->assertSame(1000.00, $studioDebt, 'Sadə sum() 778 verərdi — büro real borcundan az görər.');
    }

    /** Servisin özü çağırılanda da nəticə eyni olmalıdır (kəşlənmiş sütun ≡ yenidən hesablama). */
    public function test_the_cached_debt_column_equals_a_fresh_recalculation(): void
    {
        $project = $this->project($this->alfa);
        $this->approvedBudgetLine($project, 1500);
        $this->pay($project, 600);

        $cached = $this->debtOf($project);
        $this->inTenant($this->alfa, fn () => app(ProjectFinanceService::class)->recalculateDebt($project->fresh()));

        $this->assertSame($cached, $this->debtOf($project), 'Kəş sütunu yenidən hesablama ilə fərqlənirsə hesabat köhnə rəqəm göstərir.');
        $this->assertSame(900.00, $cached);
    }

    // =====================================================================
    // C. HESAB-FAKTURALAR
    // =====================================================================

    /** ƏDV semantikası: `total` HƏMİŞƏ `subtotal + tax`; 18% vergi geri hesablana bilir. */
    public function test_vat_is_additive_and_the_total_is_never_free_input(): void
    {
        $project = $this->project($this->alfa);
        $invoice = $this->invoice($project, subtotal: 1000, tax: 180);

        $this->assertSame('1000.00', (string) $invoice->subtotal);
        $this->assertSame('180.00', (string) $invoice->tax);
        $this->assertSame('1180.00', (string) $invoice->total, 'Ara cəm + vergi = ümumi məbləğ.');

        // Vergi dərəcəsi saxlanılmır, amma məbləğlərdən dəqiq geri çıxır: 180/1000 = 18%.
        $this->assertSame(18.0, round((float) $invoice->tax / (float) $invoice->subtotal * 100, 2));

        $this->asStaff($this->alfa, 'accountant');
        $this->assertSame(1180.00, $this->svc()->portfolio()['receivable'], 'Bütün ödənilməmiş qalıq debitor borcdur.');
    }

    /** `draft` → `sent`: qaralama debitor borca DÜŞMÜR, göndəriləndən sonra düşür. */
    public function test_a_draft_invoice_is_not_receivable_until_it_is_sent(): void
    {
        $project = $this->project($this->alfa);
        $invoice = $this->invoice($project, subtotal: 1000, tax: 180, status: 'draft');

        $this->asStaff($this->alfa, 'accountant');
        $this->assertSame(0.00, $this->svc()->portfolio()['receivable'], 'Kəsilməmiş faktura müştəridən tələb deyil.');

        $this->inTenant($this->alfa, fn () => $invoice->update(['status' => InvoiceStatus::Sent->value]));

        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status, 'Qaralamadan çıxış avtomatik statusla əzilməməlidir.');
        $this->assertSame(1180.00, $this->svc()->portfolio()['receivable']);
    }

    /** Tam ödənilmiş fakturanın silinməsi hesabatı dəyişdirmir — qalıq artıq sıfırdır. */
    public function test_deleting_a_fully_paid_invoice_leaves_the_report_unchanged(): void
    {
        $project = $this->project($this->alfa);
        $invoice = $this->invoice($project, subtotal: 1000, paid: 1000);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status, 'paid == total → status «Ödənilib».');

        $this->asStaff($this->alfa, 'accountant');
        $before = $this->svc()->portfolio();
        $this->assertSame(0.00, $before['receivable']);

        $this->inTenant($this->alfa, fn () => $invoice->delete());

        $this->assertSoftDeleted($invoice);
        $after = $this->svc()->portfolio();
        $this->assertSame(0.00, $after['receivable']);
        $this->assertSame($before['collected'], $after['collected'], 'Faktura silinməsi YIĞILMIŞ gəlirə toxunmur — gəlir ödənişlərdən gəlir.');
    }

    /**
     * QİSMƏN ödənilmiş fakturanın silinməsi müştərinin qalıq borcunu hesabatdan
     * çıxarır. Bu, silinmənin təyinatıdır (faktura ləğv olunur), amma rəqəmin
     * səssizcə itməsi mühasib üçün sürpriz olmamalıdır — davranış təsbit edilir.
     */
    public function test_deleting_a_partially_paid_invoice_drops_its_outstanding_balance(): void
    {
        $project = $this->project($this->alfa);
        $invoice = $this->invoice($project, subtotal: 1000, paid: 400);

        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        $this->asStaff($this->alfa, 'accountant');
        $this->assertSame(600.00, $this->svc()->portfolio()['receivable'], '1000 − 400 = 600 qalıq.');

        $this->inTenant($this->alfa, fn () => $invoice->delete());

        $this->assertSame(0.00, $this->svc()->portfolio()['receivable'], 'Silinmiş faktura tələb yaratmır.');
        // Sətir itmir — yumşaq silinir, yəni audit izi qalır və bərpa mümkündür.
        $this->assertSame(1, Invoice::withTrashed()->where('id', $invoice->id)->count());
    }

    /**
     * Fakturanın siyahı və redaktə səhifələri mühasib üçün AÇIQ qalmalıdır.
     *
     * Valyuta sahəsi `disabled()` edildikdən sonra forma hidratlanması sınmadığını
     * bu test qoruyur: `dehydrated()` olmadan sahə saxlanmada itir, `Select`
     * variantı olmayan dəyər (köhnə USD sətri) isə formanı partlada bilər.
     */
    public function test_the_invoice_pages_still_open_for_the_accountant(): void
    {
        $invoice = $this->invoice($this->alfa->project, subtotal: 1000, tax: 180);

        $this->asStaff($this->alfa, 'accountant');

        $this->get(route('filament.app.resources.invoices.index'))->assertOk();
        $this->get(route('filament.app.resources.invoices.edit', ['record' => $invoice]))
            ->assertOk();
        $this->get(route('filament.app.resources.invoices.create'))->assertOk();
    }

    /** Vaxtı keçmiş qalıq həm `receivable`, həm `overdue` sütununda görünür — ikiqat SAYILMIR. */
    public function test_an_overdue_balance_is_reported_once_in_each_column(): void
    {
        $project = $this->project($this->alfa);
        $this->invoice($project, subtotal: 1500, status: 'overdue', dueDate: now()->subDays(3)->toDateString());
        $this->invoice($project, subtotal: 500, status: 'sent', dueDate: now()->addDays(10)->toDateString());

        $this->asStaff($this->alfa, 'accountant');
        $p = $this->svc()->portfolio();

        $this->assertSame(2000.00, $p['receivable'], '1500 + 500.');
        $this->assertSame(1500.00, $p['overdue'], 'Yalnız vaxtı keçən 1500.');
    }

    // =====================================================================
    // D. XƏRCLƏR / SATINALMA / TƏCHİZATÇI / VALYUTA
    // =====================================================================

    /** Kateqoriya cəmləri: rədd edilmiş xərc maya dəyərinə də, kateqoriya cəminə də düşmür. */
    public function test_expense_totals_per_category_exclude_rejected_claims(): void
    {
        $project = $this->project($this->alfa);
        $this->expense($project, 900, 'material');
        $this->expense($project, 300, 'transport');
        $this->expense($project, 120.50, 'transport', 'pending');   // gözləyən xərc də maya dəyəridir
        $this->expense($project, 500, 'material', 'rejected');      // büro ödəməkdən imtina etdi

        $byCategory = $this->inTenant($this->alfa, fn () => Expense::query()
            ->where('project_id', $project->id)
            ->whereIn('status', ExpenseStatus::costBearing())
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($v) => round((float) $v, 2))
            ->all());

        $this->assertSame(900.00, $byCategory['material'], 'Rədd edilmiş 500 kateqoriya cəminə düşməməlidir.');
        $this->assertSame(420.50, $byCategory['transport'], '300 + 120.50.');

        $r = $this->svc()->forProject($project->fresh());
        $this->assertSame(1320.50, $r['expenses'], 'Hesabatdaki xərc kateqoriya cəmlərinin toplamına bərabərdir.');
        $this->assertSame(1320.50, array_sum($byCategory));
    }

    /** Pozisiyasız (yekun məbləğli) sifariş: yazılan ara cəm saxlanılır və maya dəyərinə düşür. */
    public function test_a_lump_sum_purchase_order_reaches_the_cost_line(): void
    {
        $project = $this->project($this->alfa);
        $order = $this->purchaseOrder($project, subtotal: 900, tax: 100);

        $this->assertSame('900.00', (string) $order->fresh()->subtotal, 'Pozisiya yoxdursa yazılan ara cəm qalır.');
        $this->assertSame('1000.00', (string) $order->fresh()->total, '900 + 100 vergi.');

        $r = $this->svc()->forProject($project->fresh());
        $this->assertSame(1000.00, $r['purchases']);
        $this->assertSame(1000.00, $r['cost'], 'Yekun məbləğli sifariş hesabatda görünməlidir.');
    }

    /** Pozisiyalar varsa başlıq məbləği ONLARDAN yenidən qurulur — əl ilə yazılmış ara cəm əzilir. */
    public function test_purchase_order_line_items_win_over_a_typed_subtotal(): void
    {
        $project = $this->project($this->alfa);
        $order = $this->purchaseOrder($project, subtotal: 999, tax: 54, items: [
            ['name' => 'Kran', 'qty' => 2, 'price' => 150],
        ]);

        $this->assertSame('300.00', (string) $order->fresh()->subtotal, '2 × 150 = 300; yazılmış 999 etibarsızdır.');
        $this->assertSame('354.00', (string) $order->fresh()->total, '300 + 54.');
        $this->assertSame(354.00, $this->svc()->forProject($project->fresh())['purchases']);
    }

    /** Təchizatçı üzrə xərc cəmi: yalnız öhdəlik yaradan statuslar sayılır. */
    public function test_supplier_spend_counts_only_committed_orders(): void
    {
        $project = $this->project($this->alfa);
        $supplier = $this->inTenant($this->alfa, fn () => Supplier::create(['name' => 'Mebel MMC']));

        $this->purchaseOrder($project, 1000, status: 'ordered', supplier: $supplier);
        $this->purchaseOrder($project, 400, status: 'received', supplier: $supplier);
        $this->purchaseOrder($project, 500, status: 'draft', supplier: $supplier);      // hələ sifariş deyil
        $this->purchaseOrder($project, 700, status: 'draft', supplier: $supplier)
            ->update(['status' => PurchaseOrderStatus::Cancelled->value]);              // ödəniləsi olmadı

        $committed = $this->inTenant($this->alfa, fn () => (float) $supplier->purchaseOrders()
            ->whereIn('status', PurchaseOrderStatus::costBearing())
            ->sum('total'));

        $this->assertSame(1400.00, round($committed, 2), '1000 + 400; qaralama və ləğv sayılmır.');
        $this->assertSame(4, $this->inTenant($this->alfa, fn () => $supplier->purchaseOrders()->count()));
        $this->assertSame(1400.00, $this->svc()->forProject($project->fresh())['purchases']);
    }

    /**
     * VALYUTA — büro hər şeyi AZN kimi toplayır. Xərc formasında valyuta AZN-ə
     * bağlıdır, amma MODEL səviyyəsində sütun açıqdır (idxal, konsol, köhnə
     * məlumat), ona görə bu davranış TƏSBİT edilir: çevrilmə yoxdur.
     */
    public function test_foreign_currency_expenses_are_summed_as_manat(): void
    {
        $project = $this->project($this->alfa);
        $this->expense($project, 100, 'material', 'approved', 'AZN');
        $this->expense($project, 100, 'material', 'approved', 'USD');

        $r = $this->svc()->forProject($project->fresh());

        // 100 ₼ + 100 $ = 200 ₼ kimi yığılır — məzənnə tətbiq olunmur.
        $this->assertSame(200.00, $r['expenses'], 'Çevrilmə yoxdur: USD məbləğ manat kimi toplanır (bilinən məhdudiyyət).');
    }

    /** Hesab-faktura valyutası formada AZN-ə bağlıdır — operator USD yaza bilməz. */
    public function test_the_invoice_form_does_not_offer_a_second_currency(): void
    {
        $this->asStaff($this->alfa, 'accountant');

        $field = collect(InvoiceResource::form(
            Schema::make(Livewire::test(CreateInvoice::class)->instance())
        )->getFlatFields(withHidden: true))->get('currency');

        $this->assertNotNull($field, 'Valyuta sahəsi formada olmalıdır.');
        $this->assertTrue($field->isDisabled(), 'Valyuta sahəsi açıq qalsa, operator USD yazar və portfel cəmi iki valyutanı bir kimi toplayar.');
        $this->assertSame(['AZN' => 'AZN'], $field->getOptions(), 'Yalnız AZN seçimi qalmalıdır.');
    }

    /** Mənfi satınalma sifarişi maya dəyərini AZALDIR — forma bunu bloklamalıdır. */
    public function test_the_purchase_order_form_blocks_negative_money(): void
    {
        $this->asStaff($this->alfa, 'owner');

        $fields = collect(PurchaseOrderResource::form(
            Schema::make(Livewire::test(CreatePurchaseOrder::class)->instance())
        )->getFlatFields(withHidden: true));

        // `qty`/`price` başlıq cəmini yenidən qurur, `subtotal`/`tax` isə
        // birbaşa `total`-a gedir — dördü də mənfi ola bilməz.
        foreach (['subtotal', 'tax', 'total'] as $name) {
            $field = $fields->get($name);
            $this->assertNotNull($field, "«{$name}» sahəsi formada yoxdur.");

            $min = $field->getMinValue();

            // `assertNotNull` VACİBDİR: `(int) null === 0` olduğu üçün tipsiz
            // yoxlama minimum qoyulmadığı halda da yaşıl qalır.
            $this->assertNotNull(
                $min,
                "«{$name}» sahəsində minimum dəyər qoyulmayıb — mənfi sifariş maya dəyərini azaldır və marjanı süni şişirdir."
            );
            $this->assertSame(0, (int) $min, "«{$name}» sahəsinin minimumu 0 olmalıdır.");
        }

        // Yekun məbləğ oxunaqlıdır: model onu hər halda `subtotal + tax` kimi
        // yenidən yazır, sərbəst input yalnız operatoru aldadır.
        $this->assertTrue($fields->get('total')->isDisabled(), '«Yekun» sahəsi əl ilə yazıla bilməməlidir.');
    }

    // =====================================================================
    // E. VAXT QEYDLƏRİ VƏ ƏMƏK DƏYƏRİ
    // =====================================================================

    /**
     * MƏNFİ müddətli vaxt qeydi əmək dəyərini AZALDIR.
     *
     * `TimeEntry::booted()` yalnız `started_at`+`ended_at` cütü varsa intervalı
     * yoxlayır; yalnız `duration_minutes` ilə gələn qeyd (idxal, konsol, xülasə
     * daxiletmə) yoxlamadan keçir. Hesabat isə SUM(duration × tarif) hesablayır,
     * ona görə −600 dəqiqə real 600 dəqiqəni tam silir: maya dəyəri 250 yerinə 0,
     * marja 100% görünür.
     *
     * İndi hər iki sədd yoxlanılır: model belə sətri ARTIQ QƏBUL ETMİR
     * (`TimeEntry::booted()`), hesabat isə bazada ƏVVƏLDƏN qalmış sətri maya
     * dəyərindən kənarda saxlayır — qoruma əlavə olunanadək yazılmış qeydlər
     * öz-özünə yoxa çıxmır.
     */
    public function test_a_negative_duration_entry_cannot_erase_real_labour_cost(): void
    {
        $project = $this->project($this->alfa);
        $this->pay($project, 1000);
        $this->hours($project, 600, 25);                       // real 10 saat = 250 ₼

        // 1-ci sədd: model.
        try {
            $this->hours($project, -600, 25, $this->alfa->user('visualizer'));
            $this->fail('Model mənfi müddətli vaxt qeydini qəbul etdi.');
        } catch (\RuntimeException) {
            // Gözlənilən davranış.
        }

        // 2-ci sədd: hesabat. Köhnə — qoruma əlavə olunmazdan əvvəlki — sətri
        // modelin hook-undan yan keçib birbaşa bazaya yazırıq.
        $this->inTenant($this->alfa, fn () => DB::table('time_entries')->insert([
            'tenant_id' => $this->alfa->tenant->id,
            'user_id' => $this->alfa->user('visualizer')->id,
            'project_id' => $project->id,
            'duration_minutes' => -600,
            'hourly_cost_snapshot' => 25,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $r = $this->svc()->forProject($project->fresh());

        $this->assertSame(250.00, $r['labor_cost'], 'Mənfi müddət real əmək dəyərini silməməlidir.');
        $this->assertSame(250.00, $r['cost']);
        $this->assertSame(75.0, $r['margin'], '(1000 − 250) / 1000 = 75.0%.');

        // Sətir maya dəyərindən çıxarılır, AMMA gizlədilmir: hesabat neçə qeydin
        // düzəldilməli olduğunu deyir, yoxsa həmin saatlar heç vaxt xərcə düşməz.
        $this->assertSame(1, $r['invalid_time_entries'], 'Hesabat mənfi müddətli qeydi bildirməlidir.');

        // Köhnə sətir bazada QALIR: hesabat onu susdurmur, düzəldilməli qeyd
        // kimi sayır.
        $this->assertSame(1, $this->inTenant($this->alfa, fn () => TimeEntry::query()
            ->where('project_id', $project->id)->where('duration_minutes', '<', 0)->count()));

        // Portfel kartı da eyni qaydaya tabedir.
        $this->asStaff($this->alfa, 'owner');
        $this->assertSame(250.00, $this->svc()->portfolio()['cost'], 'Portfel maya dəyəri də mənfi müddətdən qorunmalıdır.');
    }

    /**
     * TAM BÖLMƏ TƏLƏSİ — əmək dəyəri qəpiksiz kəsilirdi.
     *
     * `SUM(duration_minutes * hourly_cost_snapshot) / 60` ifadəsində SQLite hər
     * iki operand tam olduqda TAM BÖLMƏ edir; `decimal(10,2)` sütunu NUMERIC
     * affinity daşıdığı üçün 30.00 kimi tarif tam ədəd kimi saxlanılır. Bütöv
     * tarif (25, 30, 37 — ən adi hal) + 60-a bölünməyən dəqiqə sayı → qəpiklər
     * səssizcə itir və marja OLDUĞUNDAN YUXARI çıxır.
     */
    public function test_labour_cost_is_not_truncated_by_integer_division(): void
    {
        $project = $this->project($this->alfa);
        $this->pay($project, 1000);
        $this->hours($project, 70, 30);        // 70 × 30 = 2100; 2100 / 60 = 35.00 — dəqiq
        $this->hours($project, 25, 30, $this->alfa->user('visualizer'));  // 750 / 60 = 12.50

        $r = $this->svc()->forProject($project->fresh());

        // Cəmi: (2100 + 750) / 60 = 47.50. Tam bölmə 47 verirdi.
        $this->assertSame(47.50, $r['labor_cost'], 'Əmək dəyərinin qəpikləri kəsilməməlidir.');
        $this->assertSame(952.50, $r['gross_profit']);
        $this->assertSame(95.3, $r['margin'], '952.50 / 1000 × 100 = 95.25 → 95.3.');

        $this->asStaff($this->alfa, 'owner');
        $this->assertSame(47.50, $this->svc()->portfolio()['cost'], 'Portfel kartı da qəpiyi saxlamalıdır.');
    }

    /** Uzun, amma mümkün müddət olduğu kimi sayılır — hesabat süni tavan qoymur. */
    public function test_a_large_but_plausible_duration_is_costed_in_full(): void
    {
        $project = $this->project($this->alfa);
        $this->pay($project, 100000);
        $this->hours($project, 100000, 25);                    // ≈1667 saat

        $r = $this->svc()->forProject($project->fresh());

        $this->assertSame(41666.67, $r['labor_cost'], '100000 / 60 × 25 = 41666.666… → 41666.67.');
    }

    /** Tarifsiz saatlar maya dəyərinə düşmür — hesabat bunu AÇIQ bildirməlidir. */
    public function test_hours_logged_at_a_zero_rate_are_flagged_not_hidden(): void
    {
        $project = $this->project($this->alfa);
        $this->pay($project, 1000);
        $this->hours($project, 480, 0);

        $r = $this->svc()->forProject($project->fresh());

        $this->assertSame(0.00, $r['labor_cost']);
        $this->assertSame(480, $r['uncosted_minutes'], 'Tarifsiz 8 saat gizlədilməməlidir — marja süni yüksəkdir.');
        $this->assertSame(100.0, $r['margin']);
    }

    /** Üst-üstə düşən intervallar bloklanır, ona görə əmək dəyəri ikiqat yazılmır. */
    public function test_overlapping_entries_cannot_double_count_labour(): void
    {
        $project = $this->project($this->alfa);
        $designer = $this->alfa->user('designer');

        $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id, 'project_id' => $project->id,
            'started_at' => now()->setTime(9, 0), 'ended_at' => now()->setTime(13, 0),
            'hourly_cost_snapshot' => 25,
        ]));

        $blocked = false;
        try {
            $this->inTenant($this->alfa, fn () => TimeEntry::create([
                'user_id' => $designer->id, 'project_id' => $project->id,
                'started_at' => now()->setTime(10, 0), 'ended_at' => now()->setTime(12, 0),
                'hourly_cost_snapshot' => 25,
            ]));
        } catch (\RuntimeException) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'Kəsişən interval qəbul edilsə 4 saatlıq iş 6 saat kimi xərclənir.');
        $this->assertSame(100.00, $this->svc()->forProject($project->fresh())['labor_cost'], '4 saat × 25 = 100.');
    }

    /** Başqasının adına saat yazmaq scope-lu rol üçün qapalıdır (maya dəyəri yad işçiyə yazılmır). */
    public function test_a_scoped_role_cannot_log_labour_against_another_employee(): void
    {
        $designer = $this->asStaff($this->alfa, 'designer');
        $victim = $this->alfa->user('visualizer');

        $this->assertTrue($designer->can('mayLogFor', [TimeEntry::class, $designer->id]));
        $this->assertFalse(
            $designer->can('mayLogFor', [TimeEntry::class, $victim->id]),
            'Dizayner vizualizatorun adına saat yaza bilsə, yad işçiyə maya dəyəri yazılır və geri alına bilmir.'
        );

        // Sahibkar (scope-suz) yaza bilər — komanda uçotu belə işləyir.
        $owner = $this->asStaff($this->alfa, 'owner');
        $this->assertTrue($owner->can('mayLogFor', [TimeEntry::class, $victim->id]));
    }

    // =====================================================================
    // F. GİRİŞ MATRİSİ — GİZLƏTMƏK YOX, BAĞLAMAQ
    // =====================================================================

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function financeRoles(): array
    {
        return [
            'owner görür' => ['owner', true],
            'accountant görür' => ['accountant', true],
            'project_manager görmür' => ['project_manager', false],
            'designer görmür' => ['designer', false],
            'visualizer görmür' => ['visualizer', false],
            'procurement görmür' => ['procurement', false],
        ];
    }

    /** Səhifə və hər üç maliyyə vidjeti EYNİ şərtə tabedir — biri açıq, biri bağlı qalmamalıdır. */
    #[DataProvider('financeRoles')]
    public function test_the_profitability_page_and_its_widgets_share_one_gate(string $role, bool $allowed): void
    {
        $this->asStaff($this->alfa, $role);

        $this->assertSame($allowed, Profitability::canAccess(), "«{$role}» üçün səhifə icazəsi gözləniləndən fərqlidir.");
        $this->assertSame($allowed, PortfolioFinanceStats::canView(), "«{$role}» üçün portfel vidjeti.");
        $this->assertSame($allowed, CashForecastWidget::canView(), "«{$role}» üçün pul axını vidjeti.");
        $this->assertSame($allowed, ProfitabilityWidget::canView(), "«{$role}» üçün rentabellik cədvəli.");
    }

    /** Səhifə yalnız naviqasiyada gizlədilmir — birbaşa URL də 403 verir. */
    #[DataProvider('financeRoles')]
    public function test_the_profitability_route_is_unreachable_not_merely_hidden(string $role, bool $allowed): void
    {
        $status = $this->actingAs($this->alfa->user($role))
            ->get(route('filament.app.pages.profitability'))
            ->status();

        if ($allowed) {
            $this->assertSame(200, $status, "«{$role}» icazəsi olduğu halda səhifəni aça bilmədi.");

            return;
        }

        $this->assertSame(403, $status, "«{$role}» birbaşa URL ilə portfel maliyyəsinə girdi (status {$status}).");
    }

    /** Baxış səviyyəsi DƏYİŞDİRMƏ hüququ vermir. */
    public function test_a_view_only_finance_role_cannot_mutate_money(): void
    {
        $pm = $this->asStaff($this->alfa, 'project_manager');

        $this->assertSame(AccessLevel::View, AccessMatrix::level($pm, Domain::Payments), 'Layihə meneceri ödənişləri yalnız görür.');

        $invoice = $this->invoice($this->alfa->project, subtotal: 100);
        $expense = $this->expense($this->alfa->project, 100);

        $this->assertTrue($pm->can('viewAny', Invoice::class), 'Baxış icazəsi qalmalıdır.');
        $this->assertFalse($pm->can('create', Invoice::class), 'Baxış səviyyəsi faktura yaratmağa icazə verməməlidir.');
        $this->assertFalse($pm->can('update', $invoice), 'Baxış səviyyəsi fakturanı redaktə edə bilməz.');
        $this->assertFalse($pm->can('delete', $invoice));
        $this->assertFalse($pm->can('create', Expense::class));
        $this->assertFalse($pm->can('update', $expense));

        // Maliyyəsi tamamilə bağlı rol heç baxa da bilmir.
        $visualizer = $this->asStaff($this->alfa, 'visualizer');
        $this->assertSame(AccessLevel::None, AccessMatrix::level($visualizer, Domain::Payments));
        $this->assertFalse($visualizer->can('viewAny', Invoice::class));
        $this->assertFalse($visualizer->can('viewAny', Expense::class));
    }

    // =====================================================================
    // G. STUDİYA İZOLYASİYASI — VİDJET-VİDJET
    // =====================================================================

    /** Hər maliyyə vidjeti AYRILIQDA render olunanda beta studiyanın pulunu göstərməməlidir. */
    public function test_no_finance_widget_leaks_another_studios_money(): void
    {
        // Alfa: kiçik, nəzarətdə olan rəqəmlər.
        $alfaProject = $this->project($this->alfa, budgetPlan: 1000);
        $this->pay($alfaProject, 400);
        $this->pay($alfaProject, 750, 'pending', now()->addDays(10)->toDateString());
        $this->expense($alfaProject, 100);

        // Beta: markerlər — bu rəqəmlər alfa ekranında HEÇ VAXT görünməməlidir.
        $betaProject = $this->project($this->beta, budgetPlan: 55555);
        $this->pay($betaProject, 77777);
        $this->pay($betaProject, 66666, 'pending', now()->addDays(10)->toDateString());
        $this->expense($betaProject, 8888);
        $this->invoice($betaProject, subtotal: 44444, status: 'sent', dueDate: now()->addDays(5)->toDateString());

        $this->asStaff($this->alfa, 'owner');

        $p = $this->svc()->portfolio();
        $this->assertSame(400.00, $p['collected'], 'Alfa yalnız öz 400 ₼-ni yığıb.');
        $this->assertSame(100.00, $p['cost']);
        $this->assertSame(300.00, $p['gross_profit']);
        $this->assertSame(0.00, $p['receivable'], 'Betanın 44444 ₼ fakturası alfanın debitor borcu deyil.');

        $markers = ['77777', '66666', '8888', '44444', '55555', $this->beta->project->name];

        foreach ([PortfolioFinanceStats::class, CashForecastWidget::class, ProfitabilityWidget::class] as $widget) {
            $html = Livewire::test($widget)->html();

            foreach ($markers as $marker) {
                $this->assertStringNotContainsStringQuietly(
                    (string) $marker,
                    $html,
                    class_basename($widget).' vidjeti beta studiyanın «'.$marker.'» məlumatını göstərir.'
                );
            }
        }

        // Alfanın öz rəqəmi görünür — izolyasiya hesabatı boşaltmır.
        // d30 = 750 (bu ssenari) + 500 (StudioWorld-ün hazır avansı) = 1250,
        // vidjet isə minliyi boşluqla ayırır: «1 250 ₼».
        $this->assertSame(1250.00, $this->svc()->cashForecast()['d30'], 'Alfanın 30 günlük proqnozu 750 + 500 olmalıdır.');
        $this->assertStringContainsStringQuietly('400.00', Livewire::test(PortfolioFinanceStats::class)->html(), 'Alfa öz yığılmış gəlirini görmür.');
        $this->assertStringContainsStringQuietly('1 250', Livewire::test(CashForecastWidget::class)->html(), 'Alfa öz pul axını proqnozunu görmür.');
    }

    /** Beta öz tərəfindən EYNİ hesabatı öz rəqəmləri ilə görür (scope tərs tərəfə də işləyir). */
    public function test_each_studio_sees_its_own_figures(): void
    {
        $betaProject = $this->project($this->beta);
        $this->pay($betaProject, 9000);
        $this->expense($betaProject, 1000);

        $this->asStaff($this->beta, 'owner');
        $p = $this->svc()->portfolio();

        $this->assertSame(9000.00, $p['collected']);
        $this->assertSame(1000.00, $p['cost']);
        $this->assertSame(8000.00, $p['gross_profit']);
        $this->assertSame(88.9, $p['margin'], '8000 / 9000 × 100 = 88.888… → 88.9.');
    }

    /** Layihə üzrə hesabat da studiyaya bağlıdır — yad layihənin rəqəmi qarışmır. */
    public function test_per_project_figures_stay_inside_their_studio(): void
    {
        $alfaProject = $this->project($this->alfa);
        $this->pay($alfaProject, 120);

        $betaProject = $this->project($this->beta);
        $this->pay($betaProject, 31337);

        $this->asStaff($this->alfa, 'owner');
        $this->assertSame(120.00, $this->svc()->forProject($alfaProject->fresh())['revenue']);

        $this->asStaff($this->beta, 'owner');
        $this->assertSame(31337.00, $this->svc()->forProject($betaProject->fresh())['revenue']);
    }
}
