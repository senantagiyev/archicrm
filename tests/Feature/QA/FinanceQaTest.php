<?php

namespace Tests\Feature\QA;

use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\TimeEntry;
use App\Services\Finance\ProfitabilityService;
use App\Services\Finance\ProjectFinanceService;
use App\Support\Csv;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA — MALİYYƏ VƏ HESABATLARIN DÜZGÜNLÜYÜ.
 *
 * Müştərinin sualı: «rentabellik modulunun məqsədi hesabat verməkdir — bu modul
 * DOĞRU hesabat verirmi?». Ona görə burada səhifənin açılması yox, RƏQƏMİN
 * ÖZÜ yoxlanılır: hər ssenaridə gözlənilən məbləğ əl ilə hesablanıb şərhdə
 * yazılıb, sonra modulun verdiyi ilə tutuşdurulur.
 *
 * Tapıntılar `// QA TAPINTI:` şərhi ilə işarələnib və test onların HAZIRKİ
 * davranışını təsbit edir (characterisation) — yəni testlər yaşıldır, amma
 * assert-in yanındakı şərh rəqəmin niyə səhv olduğunu göstərir.
 *
 * Şərhində «— DÜZƏLDİLDİ» qeydi olan tapıntılar artıq məhsul kodunda bağlanıb
 * (`Invoice::booted()`, `Payment::booted()`); həmin testlər indi köhnə səhv
 * davranışın QAYITMADIĞINI qoruyur — qadağan edilmiş əməliyyat `RuntimeException`
 * atır və hesabat rəqəmi toxunulmaz qalır. Qeydsiz tapıntılar isə məhsul
 * qərarıdır (endirim/sətir sahələri, valyuta çevrilməsi, `budget_fact`) və
 * hələ də yalnız sənədləşdirilir.
 */
class FinanceQaTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('alpha');
    }

    private function inStudio(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    private function svc(): ProfitabilityService
    {
        // Servisin daxili memo keşi var — hər ssenaridə təzə nüsxə lazımdır.
        return new ProfitabilityService;
    }

    private function project(): Project
    {
        return $this->studio->project->fresh();
    }

    private function pay(float $amount, string $status = 'paid', ?string $due = null): Payment
    {
        return $this->inStudio(fn () => $this->studio->project->payments()->create([
            'title' => 'Ödəniş',
            'amount' => $amount,
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
            'due_date' => $due,
        ]));
    }

    private function expense(float $amount, ExpenseStatus $status = ExpenseStatus::Approved, ?string $currency = null): Expense
    {
        return $this->inStudio(fn () => Expense::create(array_filter([
            'project_id' => $this->studio->project->id,
            'category' => 'material',
            'vendor' => 'Təchizatçı',
            'amount' => $amount,
            'currency' => $currency,
            'date' => today(),
            'status' => $status->value,
            'created_by_user_id' => $this->studio->user('accountant')->id,
        ], fn ($v) => $v !== null)));
    }

    private function hours(float $hours, float $rate): TimeEntry
    {
        $designer = $this->studio->user('designer');
        $designer->forceFill(['hourly_internal_cost' => $rate])->save();

        return $this->inStudio(fn () => TimeEntry::create([
            'user_id' => $designer->id,
            'project_id' => $this->studio->project->id,
            'duration_minutes' => (int) round($hours * 60),
            'source' => 'manual',
        ]));
    }

    private function purchaseOrder(float $subtotal, float $tax = 0, string $status = 'ordered'): PurchaseOrder
    {
        return $this->inStudio(function () use ($subtotal, $tax, $status) {
            $supplier = Supplier::create(['name' => 'Mebel MMC']);

            return PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'project_id' => $this->studio->project->id,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'status' => $status,
            ]);
        });
    }

    private function invoice(array $attributes): Invoice
    {
        return $this->inStudio(fn () => Invoice::create(array_merge([
            'project_id' => $this->studio->project->id,
            'client_id' => $this->studio->client->id,
            'number' => 'INV-'.uniqid(),
            'issue_date' => today(),
            'status' => InvoiceStatus::Sent->value,
        ], $attributes)));
    }

    /**
     * Qadağan edilmiş maliyyə əməliyyatını yoxlayır: model `RuntimeException`
     * atmalıdır, mesaj gözlənilən sahəni göstərməlidir.
     *
     * `$this->expectException()` QƏSDƏN istifadə olunmur — o, testi istisna
     * anında bitirir və ondan SONRA «bazada nə qaldı» sualına cavab verən
     * rəqəmli iddiaları yazmaq mümkün olmur. Burada isə məhz o vacibdir:
     * əməliyyat rədd edilib, deməli hesabat rəqəmi TOXUNULMAZ qalmalıdır.
     */
    private function assertRejected(callable $operation, string $expectedMessageFragment): void
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                $expectedMessageFragment,
                $exception->getMessage(),
                'İstisna atıldı, amma səbəbi gözlənilən sahəni göstərmir.',
            );

            return;
        }

        $this->fail("RuntimeException gözlənilirdi («{$expectedMessageFragment}»), amma əməliyyat uğurla keçdi.");
    }

    // =====================================================================
    // 1. RENTABELLİK — 5 ƏL İLƏ HESABLANMIŞ SSENARİ
    // =====================================================================

    /** Ssenari 1 — mənfəətli layihə. */
    public function test_scenario_profitable_project_matches_hand_calculation(): void
    {
        $this->pay(12000);                    // gəlir 12 000.00
        $this->hours(8, 25);                  // əmək 8 × 25 = 200.00
        $this->expense(1500);                 // xərc 1 500.00
        $this->purchaseOrder(2800, 200);      // satınalma 2 800 + 200 vergi = 3 000.00

        $r = $this->svc()->forProject($this->project());

        $this->assertSame(12000.0, $r['revenue']);
        $this->assertSame(200.0, $r['labor_cost']);
        $this->assertSame(1500.0, $r['expenses']);
        $this->assertSame(3000.0, $r['purchases']);
        // Maya dəyəri = 200 + 1 500 + 3 000 = 4 700.00
        $this->assertSame(4700.0, $r['cost']);
        // Mənfəət = 12 000 − 4 700 = 7 300.00
        $this->assertSame(7300.0, $r['gross_profit']);
        // Marja = 7 300 / 12 000 × 100 = 60.8333… → 60.8
        $this->assertSame(60.8, $r['margin']);
    }

    /** Ssenari 2 — zərərli layihə: mənfi mənfəət və mənfi marja. */
    public function test_scenario_loss_making_project_matches_hand_calculation(): void
    {
        $this->pay(1000);
        $this->expense(1750);

        $r = $this->svc()->forProject($this->project());

        // 1 000 − 1 750 = −750.00 ; marja = −750/1 000 = −75.0%
        $this->assertSame(-750.0, $r['gross_profit']);
        $this->assertSame(-75.0, $r['margin']);
    }

    /** Ssenari 3 — sıfır gəlir: sıfıra bölmə olmamalıdır. */
    public function test_scenario_zero_revenue_never_divides_by_zero(): void
    {
        $this->expense(750);

        $r = $this->svc()->forProject($this->project());

        $this->assertSame(0.0, $r['revenue']);
        $this->assertSame(-750.0, $r['gross_profit']);
        $this->assertNull($r['margin'], 'Gəlir 0 olanda marja null olmalıdır — 0% «zərərsiz» kimi oxunur.');
        $this->assertFalse(is_nan((float) ($r['margin'] ?? 0)));
        $this->assertFalse(is_infinite((float) ($r['margin'] ?? 0)));

        // Statik düstur birbaşa da yoxlanılır — hər iki sərhəd.
        $this->assertNull(ProfitabilityService::margin(0.0, -750.0));
        $this->assertNull(ProfitabilityService::margin(0.0, 0.0));
    }

    /** Ssenari 4 — yalnız xərc, heç bir gəlir sətri yoxdur. */
    public function test_scenario_cost_only_project(): void
    {
        $this->hours(10, 12.5);   // 125.00
        $this->purchaseOrder(400); // 400.00

        $r = $this->svc()->forProject($this->project());

        $this->assertSame(0.0, $r['revenue']);
        $this->assertSame(525.0, $r['cost']);       // 125 + 400
        $this->assertSame(-525.0, $r['gross_profit']);
        $this->assertNull($r['margin']);
    }

    /** Ssenari 5 — qismən ödənilmiş: gəlirə YALNIZ `paid` ödəniş düşür. */
    public function test_scenario_partially_paid_project_counts_only_collected_money(): void
    {
        $this->pay(4000, 'paid');
        $this->pay(6000, 'pending', today()->addDays(10)->toDateString());
        $this->pay(2000, 'overdue', today()->subDays(5)->toDateString());
        $this->expense(1000);

        $r = $this->svc()->forProject($this->project());

        // Yığılmış 4 000; gözləyən 6 000 və gecikmiş 2 000 gəlir deyil.
        $this->assertSame(4000.0, $r['revenue']);
        $this->assertSame(3000.0, $r['gross_profit']);
        $this->assertSame(75.0, $r['margin']);
    }

    /**
     * QA TAPINTI [ORTA] — ARXİVLƏNMİŞ LAYİHƏ HESABATI POZUR.
     *
     * `ProfitabilityWidget` cədvəli `status != archived` şərti ilə qurulur,
     * `portfolio()['projected']` də arxivi çıxarır; LAKİN `collected`, `cost`
     * və `gross_profit` arxivlənmiş layihənin pulunu saxlayır. Nəticədə
     * yuxarıdakı kartlar aşağıdakı cədvəlin sətirlərinin cəmi ilə HEÇ VAXT
     * üst-üstə düşmür və marja iki fərqli toplum üzərindən hesablanır.
     */
    public function test_archived_project_money_stays_in_the_cards_but_leaves_the_table(): void
    {
        $this->pay(9000);
        $this->expense(1000);
        $this->inStudio(fn () => $this->studio->project->forceFill([
            'status' => ProjectStatus::Archived->value,
            'budget_plan' => 20000,
        ])->save());

        $p = $this->inStudio(fn () => $this->svc()->portfolio());

        // Arxivlənmiş layihənin pulu portfel kartlarında QALIR:
        $this->assertSame(9000.0, $p['collected']);
        $this->assertSame(1000.0, $p['cost']);
        // …amma plan büdcəsi çıxarılır (20 000 gözlənilirdi, 0 gəlir):
        $this->assertSame(0.0, $p['projected'], 'Plan büdcə arxivi çıxarır, yığılmış gəlir isə saxlayır.');

        // Cədvəl sorğusu (widget ilə eyni) arxivlənmiş layihəni göstərmir:
        $visible = $this->inStudio(fn () => Project::query()
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->pluck('id')->all());
        $this->assertNotContains($this->studio->project->id, $visible);

        // Yəni kartdakı 9 000 ₼ gəlirin mənbəyi ekranda heç bir sətirdə yoxdur.
        $tableRevenue = 0.0;
        foreach ($visible as $id) {
            $tableRevenue += $this->svc()->forProject(Project::find($id))['revenue'];
        }
        $this->assertSame(0.0, $tableRevenue);
        $this->assertNotSame($p['collected'], $tableRevenue);
    }

    // =====================================================================
    // 2. SIFIRA BÖLMƏ VƏ NULL DƏYƏRLƏR
    // =====================================================================

    /** Boş layihə — bütün sahələr 0/null, heç bir xəta yoxdur. */
    public function test_empty_project_returns_zeroes_not_errors(): void
    {
        $r = $this->svc()->forProject($this->studio->otherProject);

        $this->assertSame(0.0, $r['revenue']);
        $this->assertSame(0.0, $r['cost']);
        $this->assertSame(0.0, $r['gross_profit']);
        $this->assertNull($r['margin']);
        $this->assertSame(0, $r['uncosted_minutes']);
        $this->assertSame(0.0, $r['projected_revenue']);
    }

    /**
     * Qiyməti boş olan smeta sətri: baza NOT NULL ilə bloklayır, yəni hesabata
     * «null × qiymət» sətri düşə bilmir — bu, doğru davranışdır.
     */
    public function test_budget_line_rejects_null_prices(): void
    {
        $this->expectException(QueryException::class);

        $this->inStudio(fn () => $this->studio->project->budgetLines()->create([
            'work_type' => 'Boş sətir', 'unit' => 'ədəd', 'qty' => 3,
            'work_price' => null, 'material_price' => null, 'position' => 9,
        ]));
    }

    /** Sıfır qiymətli sətir qanunidir və cəmi tam 0.00 verir. */
    public function test_budget_line_with_zero_prices_totals_to_zero(): void
    {
        $line = $this->inStudio(fn () => $this->studio->project->budgetLines()->create([
            'work_type' => 'Sıfır qiymət', 'unit' => 'ədəd', 'qty' => 3,
            'work_price' => 0, 'material_price' => 0, 'position' => 9,
        ]));

        $this->assertSame('0.00', (string) $line->fresh()->total);
    }

    /** Komplektasiya sətrində boş endirim/ehtiyat — cəm yenə düzgündür. */
    public function test_procurement_item_with_null_discount_is_not_discounted(): void
    {
        $item = $this->inStudio(fn () => $this->studio->project->procurementItems()->create([
            'name' => 'Stol', 'qty' => 2, 'price' => 150,
            'discount_percent' => null, 'purchase_status' => 'planned',
        ]));

        // 2 × 150 = 300.00, ehtiyat 0, çatdırılma 0
        $this->assertSame('300.00', (string) $item->fresh()->total);
        $this->assertSame(300.0, $item->fresh()->totalWithDiscount());
    }

    /** Tarifsiz saatlar maya dəyərinə düşmür, amma hesabat bunu bildirir. */
    public function test_uncosted_hours_are_flagged_not_hidden(): void
    {
        $this->pay(1000);
        $visualizer = $this->studio->user('visualizer');
        $visualizer->forceFill(['hourly_internal_cost' => 0])->save();
        $this->inStudio(fn () => TimeEntry::create([
            'user_id' => $visualizer->id,
            'project_id' => $this->studio->project->id,
            'duration_minutes' => 480,
            'source' => 'manual',
        ]));

        $r = $this->svc()->forProject($this->project());

        $this->assertSame(0.0, $r['labor_cost']);
        $this->assertSame(480, $r['uncosted_minutes'], '8 saat tarifsiz iş hesabatda bayraqlanmalıdır.');
        $this->assertSame(100.0, $r['margin'], 'Tarifsiz saatlar marjanı 100% göstərir — ona görə bayraq vacibdir.');
    }

    // =====================================================================
    // 3. YUVARLAQLAŞDIRMA VƏ SÜTUN TİPLƏRİ
    // =====================================================================

    /**
     * Pul sütunları float DEYİL — hamısı `decimal(12,2)`. Bu, miqyaslı float
     * sürüşməsinin qarşısını alır; test migrasiyaların tipini bilavasitə
     * bazadan oxuyur ki, gələcəkdə kimsə float-a keçirsə dərhal qırmızı olsun.
     */
    public function test_money_columns_are_decimal_not_float(): void
    {
        $expected = [
            'invoices' => ['subtotal', 'tax', 'total', 'paid_amount'],
            'payments' => ['amount'],
            'expenses' => ['amount'],
            'purchase_orders' => ['subtotal', 'tax', 'total'],
            'budget_lines' => ['qty', 'work_price', 'material_price', 'total'],
            'procurement_items' => ['price', 'qty', 'total'],
            'projects' => ['budget_plan', 'budget_fact', 'debt'],
            'time_entries' => ['hourly_cost_snapshot'],
        ];

        foreach ($expected as $table => $columns) {
            $types = collect(DB::select("PRAGMA table_info({$table})"))
                ->mapWithKeys(fn ($c) => [$c->name => strtolower($c->type)]);

            foreach ($columns as $column) {
                $type = $types[$column] ?? '';

                // SQLite `decimal(12,2)` sütununu `numeric` kimi bildirir —
                // vacib olan tipin `real`/`float`/`double` OLMAMASIDIR.
                $this->assertTrue(
                    str_contains($type, 'decimal') || str_contains($type, 'numeric'),
                    "{$table}.{$column} pul sütunudur, tipi decimal olmalıdır, faktiki: «{$type}».",
                );

                foreach (['real', 'float', 'double'] as $floating) {
                    $this->assertStringNotContainsString(
                        $floating,
                        $type,
                        "{$table}.{$column} float tipindədir — qəpik sürüşməsi qaçılmazdır.",
                    );
                }
            }
        }
    }

    /** Klassik 0.1 + 0.2 sınağı — cəmlərdə qəpik sürüşməsi yoxdur. */
    public function test_small_amounts_do_not_drift(): void
    {
        $this->pay(0.10);
        $this->pay(0.20);
        $this->expense(0.10);
        $this->expense(0.10);
        $this->expense(0.10);

        $r = $this->svc()->forProject($this->project());

        $this->assertSame(0.30, $r['revenue'], '0.10 + 0.20 tam 0.30 olmalıdır.');
        $this->assertSame(0.30, $r['expenses'], '3 × 0.10 tam 0.30 olmalıdır.');
        $this->assertSame(0.0, $r['gross_profit']);
        $this->assertSame(0.0, $r['margin']);
    }

    /** Uzun ondalıqlı dəqiqə/tarif hesablaması ikinci onluğa yuvarlaqlaşır. */
    public function test_labour_cost_rounds_to_two_decimals(): void
    {
        // 50 dəqiqə × 33.33 ₼/saat = 27.775 → 27.78
        $designer = $this->studio->user('designer');
        $designer->forceFill(['hourly_internal_cost' => 33.33])->save();
        $this->inStudio(fn () => TimeEntry::create([
            'user_id' => $designer->id,
            'project_id' => $this->studio->project->id,
            'duration_minutes' => 50,
            'source' => 'manual',
        ]));

        $r = $this->svc()->forProject($this->project());

        $this->assertSame(27.78, $r['labor_cost']);
    }

    // =====================================================================
    // 4. HESAB-FAKTURA (INVOICE)
    // =====================================================================

    /**
     * QA TAPINTI [CİDDİ] — `total` sahəsi `subtotal + tax` ilə HEÇ YERDƏ
     * uzlaşdırılmır.
     *
     * `PurchaseOrder::booted()` sifarişdə məhz bunu edir
     * (`total = subtotal + tax`), `Invoice` modelində isə heç bir `saving()`
     * hook yoxdur — üç sahə də sərbəst yazılan inputdur. Nəticə: debitor borc
     * kartı operatorun səhv yazdığı rəqəmi olduğu kimi göstərir.
     *
     * Gözlənilən: 1 000 + 180 = 1 180.00 ; Faktiki: 500.00
     *
     * — DÜZƏLDİLDİ (`Invoice::booted()` → `saving`).
     *
     * İnvariant artıq modeldə saxlanılır, amma tək istiqamətli deyil: hansı
     * tərəfin doğru olduğunu saxlanmada NƏYİN dirty olduğu həll edir.
     *  • `subtotal`/`tax` dəyişibsə (və ya heç nə) → `total = subtotal + tax`;
     *    yəni 1 000 + 180 yazılmış sətirdə `total` 500 QALA BİLMİR — 1 180 olur.
     *  • yalnız `total` dəyişibsə → operator sətirsiz «yekun» məbləğ yazıb,
     *    ona görə `subtotal = total − tax` geri hesablanır.
     * Hər iki iş üsulu işləyir, amma DB-də üç sahə həmişə uzlaşmış qalır və
     * debitor borc kartı artıq uzlaşmayan rəqəm göstərə bilmir.
     */
    public function test_invoice_total_is_recalculated_from_subtotal_and_tax(): void
    {
        // Üç sahə də yazılıb → komponentlər doğru sayılır, `total` onlardan qurulur.
        $invoice = $this->invoice(['subtotal' => 1000, 'tax' => 180, 'total' => 500]);

        $this->assertSame('1180.00', (string) $invoice->fresh()->total,
            'Səhv yazılmış 500 kənara atılır: 1 000 + 180 = 1 180.00.');
        $this->assertSame('1000.00', (string) $invoice->fresh()->subtotal);
        $this->assertSame('180.00', (string) $invoice->fresh()->tax);

        // Yalnız `total` yazılıb → `subtotal` ondan geri hesablanır (vergi 0).
        $lumpSum = $this->invoice(['total' => 5000]);

        $this->assertSame('5000.00', (string) $lumpSum->fresh()->total);
        $this->assertSame('5000.00', (string) $lumpSum->fresh()->subtotal,
            'Sətirsiz «yekun» fakturada subtotal = total − tax = 5 000 − 0.');

        // Yenilənmə də eyni qayda ilə işləyir: yalnız `total` dəyişdi →
        // subtotal = 2 360 − 180 = 2 180.00.
        $this->inStudio(fn () => $invoice->update(['total' => 2360]));
        $this->assertSame('2180.00', (string) $invoice->fresh()->subtotal);

        // Komponent dəyişdi → cəm yenidən qurulur: 1 000 + 180 = 1 180.00.
        $this->inStudio(fn () => $invoice->update(['subtotal' => 1000]));
        $this->assertSame('1180.00', (string) $invoice->fresh()->total);

        // Debitor borc kartı artıq yalnız uzlaşmış rəqəmləri görür:
        // 1 180 + 5 000 = 6 180.00
        $p = $this->inStudio(fn () => $this->svc()->portfolio());
        $this->assertSame(6180.0, $p['receivable']);
    }

    /**
     * QA TAPINTI [ORTA] — hesab-fakturada ENDİRİM sahəsi ümumiyyətlə yoxdur.
     *
     * Sxemdə yalnız `subtotal`, `tax`, `total`, `paid_amount` var; `discount`
     * sütunu da, sətir-sətir (`invoice_items`) cədvəli də yoxdur. Yəni
     * «cəmi = sətirlərin cəmi + vergi − endirim» düsturunun iki komponenti
     * sistemdə mövcud deyil — endirim əl ilə `total`-a «yedirdilir» və
     * hesabatda izlənə bilmir.
     */
    public function test_invoice_has_no_discount_or_line_items(): void
    {
        $columns = collect(DB::select('PRAGMA table_info(invoices)'))->pluck('name');

        $this->assertNotContains('discount', $columns, 'Endirim sütunu yoxdur — düstur natamamdır.');
        $this->assertFalse(
            collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name='invoice_items'"))->isNotEmpty(),
            'Hesab-faktura sətirləri cədvəli yoxdur — cəm sətirlərdən hesablana bilmir.',
        );
    }

    /** Qismən ödəniş: qalıq = total − paid_amount, kart da bunu göstərir. */
    public function test_partial_payment_leaves_the_correct_balance(): void
    {
        $this->invoice(['subtotal' => 5000, 'tax' => 0, 'total' => 5000, 'paid_amount' => 1500]);

        $p = $this->inStudio(fn () => $this->svc()->portfolio());

        // 5 000 − 1 500 = 3 500.00
        $this->assertSame(3500.0, $p['receivable']);
    }

    /**
     * QA TAPINTI [CİDDİ] — ARTIQ ÖDƏNİŞ MƏNFİ DEBİTOR BORC YARADIR VƏ
     * BAŞQA FAKTURANIN BORCUNU «YEYİR».
     *
     * `portfolio()` receivable-i `SUM(total - paid_amount)` kimi yığır
     * (ProfitabilityService.php:138). `paid_amount > total` üçün nə modeldə,
     * nə formada yoxlama var (`InvoiceResource` yalnız `minValue(0)` qoyur).
     *
     * Ssenari: A fakturası 1 000, ödənilib 3 000 (səhv yazılış/artıq köçürmə);
     * B fakturası 5 000, ödənilməyib.
     * Gözlənilən debitor borc: 5 000.00 (A-nın artığı borcu bağlamır).
     * Faktiki: 3 000.00 — studiya 2 000 ₼ borcu görmür.
     *
     * — DÜZƏLDİLDİ (`Invoice::booted()` → `paid > total` üçün `RuntimeException`).
     *
     * Məbləğ SƏSSİZCƏ `total`-a KƏSİLMİR: kəsilsəydi kassaya real daxil olmuş
     * artıq pul heç yerdə qeydə alınmazdı. Operator ya rəqəmi düzəltməli,
     * ya ayrıca faktura kəsməlidir. `paid == total` sərhədi qanunidir.
     */
    public function test_overpaid_invoice_is_rejected_and_the_real_debt_stays_visible(): void
    {
        $this->invoice(['total' => 5000, 'paid_amount' => 0, 'number' => 'B']);

        $this->assertRejected(
            fn () => $this->invoice(['total' => 1000, 'paid_amount' => 3000, 'number' => 'A']),
            'Ödənilmiş məbləğ',
        );

        // Rədd edilən sətir bazaya DÜŞMÜR: yalnız B fakturası var…
        $this->assertSame(1, $this->inStudio(fn () => Invoice::count()),
            'Artıq ödənişli faktura yazılmamalıdır — bazada yalnız B qalır.');
        // …və borc tam görünür: 5 000 − 0 = 5 000.00 (əvvəl 3 000 göstərilirdi).
        $this->assertSame(5000.0, $this->inStudio(fn () => $this->svc()->portfolio())['receivable']);

        // Sərhəd: tam ödəniş (`paid == total`) icazəlidir və status «Ödənilib»
        // olduğu üçün debitor borca düşmür — B-nin 5 000-i olduğu kimi qalır.
        $this->invoice(['total' => 1000, 'paid_amount' => 1000, 'number' => 'A']);

        $this->assertSame(2, $this->inStudio(fn () => Invoice::count()));
        $this->assertSame(5000.0, $this->inStudio(fn () => $this->svc()->portfolio())['receivable']);
    }

    /**
     * QA TAPINTI [CİDDİ] — MƏNFİ MƏBLƏĞLİ FAKTURA MODEL SƏVİYYƏSİNDƏ QƏBUL EDİLİR.
     *
     * `Expense::booted()` mənfi məbləği bloklayır (Expense.php:41), `Invoice`
     * modelində isə belə qoruma yoxdur: `minValue(0)` yalnız Filament formasında
     * işləyir, idxal/konsol/API yolu ilə mənfi faktura yazıla bilər və birbaşa
     * debitor borcu azaldır.
     *
     * Gözlənilən: istisna (Expense kimi). Faktiki: −4 000.00 saxlanılır.
     *
     * — DÜZƏLDİLDİ (`Invoice::booted()` → mənfi sahə üçün `RuntimeException`).
     *
     * Qoruma DÖRD sahəni də əhatə edir — `total`, `tax`, `subtotal`,
     * `paid_amount` — çünki hər biri ayrı-ayrılıqda debitor borc düsturuna
     * (`SUM(total - paid_amount)`) girir. Artıq idxal, konsol və API yolu ilə
     * də mənfi sətir yazıla bilmir; borc rəqəmi toxunulmaz qalır.
     */
    public function test_a_negative_invoice_is_rejected_and_the_receivable_is_untouched(): void
    {
        $this->invoice(['total' => 5000, 'paid_amount' => 0]);

        $this->assertRejected(
            fn () => $this->invoice(['total' => -4000, 'paid_amount' => 0]),
            'Ümumi məbləğ',
        );

        // Dörd sahənin hər biri ayrıca bloklanır (cəm müsbət qalsa belə):
        $this->assertRejected(
            fn () => $this->invoice(['subtotal' => -100, 'tax' => 200]),   // total = 100 ≥ 0
            'Ara cəm',
        );
        $this->assertRejected(
            fn () => $this->invoice(['subtotal' => 1000, 'tax' => -50]),   // total = 950 ≥ 0
            'Vergi',
        );
        $this->assertRejected(
            fn () => $this->invoice(['total' => 1000, 'paid_amount' => -5]),
            'Ödənilmiş məbləğ',
        );

        // Heç bir mənfi sətir bazaya düşmədi — yalnız ilk faktura var…
        $this->assertSame(1, $this->inStudio(fn () => Invoice::count()));
        // …və debitor borc 5 000.00 olaraq qalır (əvvəl 1 000-ə enirdi).
        $this->assertSame(5000.0, $this->inStudio(fn () => $this->svc()->portfolio())['receivable']);
    }

    /**
     * QA TAPINTI [CİDDİ] — STATUS AVTOMATİK DƏYİŞMİR (unpaid → partial → paid).
     *
     * `paid_amount` `total`-a bərabər olsa belə status `sent` qalır, çünki
     * `Invoice` modelində status maşını yoxdur. Nəticə iki yerdə görünür:
     * 1) faktura tam ödənilib, amma hələ də «Göndərilib» kimi siyahıdadır;
     * 2) `portfolio()` onu `unpaid` statusları siyahısında saxlayır və
     *    `total - paid_amount = 0` olduğu üçün borc cəmi düz çıxır — yəni
     *    səhv yalnız statusdadır, amma «Vaxtı keçib» kartı üçün kritikdir.
     *
     * — DÜZƏLDİLDİ (`Invoice::booted()` → status maşını).
     *
     * Qayda: `paid >= total > 0` → «Ödənilib»; `0 < paid < total` → «Qismən
     * ödənilib»; `paid == 0` və əvvəlki status Ödənilib/Qismən idisə →
     * «Göndərilib» (səhv yazılışın geri götürülməsi). İki istisna qəsdəndir:
     *  • «Vaxtı keçib» QİSMƏN ödənişdə saxlanılır — gecikmə qismən ödənişlə
     *    aradan qalxmır və «Vaxtı keçib» kartı daha kritik məlumat daşıyır;
     *    tam ödənişdə isə bağlanır, çünki borc qalmır;
     *  • `draft` və `cancelled` ödəniş axınından KƏNARDIR — qaralama hələ
     *    kəsilməyib, ləğv isə şüurlu qərardır, avtomatika onları əzmir.
     */
    public function test_invoice_status_follows_the_paid_amount(): void
    {
        $invoice = $this->invoice(['total' => 1000, 'paid_amount' => 0]);
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);

        // 400 / 1 000 → qismən
        $this->inStudio(fn () => $invoice->update(['paid_amount' => 400]));
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        // 1 000 / 1 000 → tam
        $this->inStudio(fn () => $invoice->update(['paid_amount' => 1000]));
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        // Ödəniş geri götürüldü → yenidən ödənilməmiş
        $this->inStudio(fn () => $invoice->update(['paid_amount' => 0]));
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);

        // «Vaxtı keçib» qismən ödənişdə qorunur, tam ödənişdə bağlanır.
        $overdue = $this->invoice([
            'total' => 2000, 'paid_amount' => 0,
            'due_date' => today()->subDays(3),
            'status' => InvoiceStatus::Overdue->value,
        ]);

        $this->inStudio(fn () => $overdue->update(['paid_amount' => 500]));
        $this->assertSame(InvoiceStatus::Overdue, $overdue->fresh()->status,
            '500/2 000 gecikməni aradan qaldırmır — status «Vaxtı keçib» qalmalıdır.');

        $this->inStudio(fn () => $overdue->update(['paid_amount' => 2000]));
        $this->assertSame(InvoiceStatus::Paid, $overdue->fresh()->status,
            'Borc qalmadı — «Vaxtı keçib» bağlanır.');

        // Qaralama və ləğv edilmiş faktura toxunulmazdır (tam ödənilsə belə).
        $draft = $this->invoice([
            'total' => 1000, 'paid_amount' => 1000,
            'status' => InvoiceStatus::Draft->value,
        ]);
        $this->assertSame(InvoiceStatus::Draft, $draft->fresh()->status);

        $cancelled = $this->invoice([
            'total' => 1000, 'paid_amount' => 1000,
            'status' => InvoiceStatus::Cancelled->value,
        ]);
        $this->assertSame(InvoiceStatus::Cancelled, $cancelled->fresh()->status);

        // Rəqəmlə nəticə: debitor borca YALNIZ birinci faktura düşür —
        // 1 000 − 0 = 1 000.00 (gecikmiş artıq «Ödənilib», qaralama və ləğv
        // isə heç vaxt sayılmır).
        $p = $this->inStudio(fn () => $this->svc()->portfolio());
        $this->assertSame(1000.0, $p['receivable']);
        $this->assertSame(0.0, $p['overdue'], 'Tam ödənilmiş faktura «Gecikmiş» kartından çıxdı.');
    }

    /**
     * QA TAPINTI [ORTA] — «BU GÜN ÖDƏNİŞ TARİXİ» SUALINA İKİ FƏRQLİ CAVAB VAR.
     *
     * `Invoice::isOverdue()` (Invoice.php:72) `due_date->isPast()` yazır; tarix
     * cast-ı 00:00:00 verdiyi üçün BU GÜN ödəniş tarixi olan faktura günün
     * qalan hissəsində artıq «gecikmiş» sayılır.
     * `ProfitabilityService::portfolio()` isə `whereDate('due_date','<',today())`
     * yazır (ProfitabilityService.php:144) — bu gün gecikmiş SAYILMIR.
     *
     * Gözlənilən: bir sərhəd. Faktiki: model «gecikib» deyir (true),
     * hesabat kartı 0.00 ₼ göstərir.
     */
    public function test_invoice_due_today_is_overdue_for_the_model_but_not_for_the_report(): void
    {
        $this->travelTo(today()->setTime(14, 0));

        $invoice = $this->invoice(['total' => 2000, 'paid_amount' => 0, 'due_date' => today()]);

        $this->assertTrue($invoice->fresh()->isOverdue(), 'Model: bu gün ödəniş tarixi = gecikmiş.');

        $p = $this->inStudio(fn () => $this->svc()->portfolio());
        $this->assertSame(0.0, $p['overdue'], 'Hesabat: bu gün ödəniş tarixi = gecikmiş DEYİL.');
        $this->assertSame(2000.0, $p['receivable']);
    }

    /** Dünən vaxtı çatmış faktura hər iki yerdə gecikmişdir — sərhədin o biri tərəfi. */
    public function test_invoice_due_yesterday_is_overdue_everywhere(): void
    {
        $this->invoice(['total' => 2000, 'paid_amount' => 500, 'due_date' => today()->subDay()]);

        $p = $this->inStudio(fn () => $this->svc()->portfolio());

        $this->assertSame(1500.0, $p['overdue'], '2 000 − 500 = 1 500 gecikmiş qalıq.');
    }

    /** Ləğv edilmiş faktura nə borca, nə gecikməyə düşür. */
    public function test_cancelled_invoice_leaves_the_receivable(): void
    {
        $this->invoice([
            'total' => 9000, 'paid_amount' => 0,
            'due_date' => today()->subDays(30),
            'status' => InvoiceStatus::Cancelled->value,
        ]);

        $p = $this->inStudio(fn () => $this->svc()->portfolio());

        $this->assertSame(0.0, $p['receivable']);
        $this->assertSame(0.0, $p['overdue']);
    }

    /**
     * QA TAPINTI [CİDDİ] — VALYUTA QARIŞIĞI: USD MƏBLƏĞ AZN KİMİ TOPLANIR.
     *
     * `invoices.currency` və `expenses.currency` sütunları var, lakin
     * `portfolio()` (ProfitabilityService.php:126-145) məzənnə nəzərə almadan
     * `SUM(total - paid_amount)` / `SUM(amount)` edir, kartlar isə nəticəni
     * «₼» ilə yazır (PortfolioFinanceStats.php:31).
     *
     * Ssenari: 1 000 AZN + 1 000 USD faktura.
     * Gözlənilən: ya çevrilmiş məbləğ, ya valyuta üzrə ayrı sətir.
     * Faktiki: 2 000.00 ₼ — 1 000 USD 1 000 ₼ kimi sayılır.
     */
    public function test_foreign_currency_amounts_are_summed_as_if_they_were_manat(): void
    {
        $this->invoice(['total' => 1000, 'paid_amount' => 0, 'currency' => 'AZN']);
        $this->invoice(['total' => 1000, 'paid_amount' => 0, 'currency' => 'USD']);
        $this->expense(100, ExpenseStatus::Approved, 'AZN');
        $this->expense(100, ExpenseStatus::Approved, 'USD');

        $p = $this->inStudio(fn () => $this->svc()->portfolio());

        $this->assertSame(2000.0, $p['receivable'], '1 000 AZN + 1 000 USD = 2 000 ₼ kimi göstərilir.');
        $this->assertSame(200.0, $p['cost'], '100 AZN + 100 USD = 200 ₼ kimi göstərilir.');
    }

    // =====================================================================
    // 5. ÖDƏNİŞLƏR
    // =====================================================================

    /**
     * QA TAPINTI [CİDDİ] — MƏNFİ/SIFIR ÖDƏNİŞ MODEL SƏVİYYƏSİNDƏ BLOKLANMIR.
     *
     * `Expense` modelində `amount <= 0` istisna verir (Expense.php:41), `Payment`
     * modelində isə belə hook yoxdur — `minValue(0.01)` yalnız
     * `PaymentsRelationManager.php:38` formasındadır. Mənfi ödəniş həm
     * rentabellik gəlirini, həm `projects.debt` rəqəmini pozur.
     *
     * Gözlənilən: istisna. Faktiki: gəlir 1 000 − 400 = 600.00 olur.
     *
     * — DÜZƏLDİLDİ (`Payment::booted()` → `amount <= 0` üçün `RuntimeException`).
     *
     * Sıfır da qəbul edilmir: məbləğsiz ödəniş sətri heç bir maliyyə hadisəsini
     * ifadə etmir, amma proqnoz səbətlərində və siyahılarda boş sətir kimi
     * görünərək rəqəmləri «şumlayır».
     */
    public function test_a_negative_payment_is_rejected_and_revenue_is_untouched(): void
    {
        $this->pay(1000);

        $this->assertRejected(fn () => $this->pay(-400), 'sıfırdan böyük olmalıdır');
        $this->assertRejected(fn () => $this->pay(0), 'sıfırdan böyük olmalıdır');

        // Gəlir toxunulmadı: 1 000.00 (əvvəl 1 000 − 400 = 600 olurdu).
        $r = $this->svc()->forProject($this->project());
        $this->assertSame(1000.0, $r['revenue']);

        // Bazada yalnız iki sətir var: StudioWorld-ün 500 ₼ gözləyən avansı və
        // bu testin 1 000 ₼-lıq ödənilmiş sətri. Rədd edilənlər yazılmadı.
        $this->assertSame(2, $this->inStudio(fn () => $this->studio->project->payments()->count()));
        $this->assertSame('1000.00', (string) $this->inStudio(
            fn () => $this->studio->project->payments()->where('status', 'paid')->sole()->amount
        ));
    }

    /** Ödənilmiş ödəniş proqnoza DÜŞMÜR — proqnoz yalnız gözlənilən puldur. */
    public function test_paid_payments_are_excluded_from_the_cash_forecast(): void
    {
        $this->pay(5000, 'paid');
        $this->pay(1000, 'pending', today()->addDays(10)->toDateString());

        $f = $this->inStudio(fn () => $this->svc()->cashForecast());

        // StudioWorld özü 500 ₼ gözləyən avans yaradır (1 həftə sonra): 500 + 1 000
        $this->assertSame(1500.0, $f['d30']);
        $this->assertSame(0.0, $f['overdue'], '5 000 ₼ ödənilmiş pul proqnoza düşməməlidir.');
    }

    /** Keçmiş plan tarixli gözləyən ödəniş «gecikmiş» səbətindədir. */
    public function test_past_due_pending_payment_lands_in_the_overdue_bucket(): void
    {
        $this->pay(700, 'pending', today()->subDay()->toDateString());

        $f = $this->inStudio(fn () => $this->svc()->cashForecast());

        $this->assertSame(700.0, $f['overdue']);
        $this->assertSame(500.0, $f['d30'], 'StudioWorld-ün 500 ₼ avansı yerində qalır.');
    }

    /**
     * QA TAPINTI [ORTA] — ÖDƏNİŞ TARİXİ BU GÜN OLAN PUL «GECİKMİŞ» SAYILMIR,
     * statusu `overdue` olsa belə 30 günlük səbətə düşür.
     *
     * `cashForecast()` səbəti YALNIZ tarixlə seçir (ProfitabilityService.php:197),
     * statusa baxmır. Ssenari: statusu «Gecikib», plan tarixi bu gün, 900 ₼.
     * Gözlənilən: «Gecikmiş» kartı 900.00. Faktiki: «30 gün» kartı 900.00,
     * «Gecikmiş» 0.00.
     */
    public function test_payment_due_today_is_forecast_as_upcoming_even_when_marked_overdue(): void
    {
        $this->pay(900, 'overdue', today()->toDateString());

        $f = $this->inStudio(fn () => $this->svc()->cashForecast());

        $this->assertSame(0.0, $f['overdue'], 'Statusu «Gecikib» olan ödəniş gecikmiş səbətinə düşmədi.');
        $this->assertSame(1400.0, $f['d30'], '900 ₼ «gecikmiş» pul 500 ₼ avansla birlikdə «30 gün» kartına yazıldı.');
    }

    /** Səbət sərhədləri: 30 / 31 / 60 / 61 / 90 / 91 gün. */
    public function test_cash_forecast_bucket_boundaries(): void
    {
        $this->pay(10, 'pending', today()->addDays(30)->toDateString());
        $this->pay(20, 'pending', today()->addDays(31)->toDateString());
        $this->pay(30, 'pending', today()->addDays(60)->toDateString());
        $this->pay(40, 'pending', today()->addDays(61)->toDateString());
        $this->pay(50, 'pending', today()->addDays(90)->toDateString());
        $this->pay(60, 'pending', today()->addDays(91)->toDateString());
        $this->pay(70, 'pending', null);

        $f = $this->inStudio(fn () => $this->svc()->cashForecast());

        $this->assertSame(510.0, $f['d30']);  // 30 gün + StudioWorld-ün 500 ₼ avansı
        $this->assertSame(50.0, $f['d60']);   // 31 + 60
        $this->assertSame(90.0, $f['d90']);   // 61 + 90
        $this->assertSame(60.0, $f['later']); // 91
        $this->assertSame(70.0, $f['undated'], 'Tarixsiz ödəniş proqnozdan düşməməlidir.');

        // Səbətlərin cəmi bütün gözlənilən pula bərabərdir: 500+10+20+30+40+50+60+70 = 780
        $this->assertSame(780.0, array_sum($f), 'Səbətlərin cəmi gözlənilən bütün pula bərabər olmalıdır.');
    }

    // =====================================================================
    // 6. BÜDCƏ (PLAN) vs FAKT
    // =====================================================================

    /**
     * QA TAPINTI [CİDDİ] — «BÜDCƏ (FAKT)» HEÇ NƏDƏN HESABLANMIR.
     *
     * `projects.budget_fact` yalnız `ProjectResource.php:124`-dəki əl ilə
     * doldurulan inputdur; nə `Expense`, nə `PurchaseOrder`, nə `TimeEntry`
     * observeri onu yeniləmir (`budget_fact` app/ altında yalnız 3 yerdə
     * görünür və heç birində hesablanmır).
     *
     * Nəticə: `RunAutomationTick::budgetOverrunAlerts()` (rule-30,
     * RunAutomationTick.php:282 `whereColumn('budget_fact','>','budget_plan')`)
     * REAL xərcləmədən heç vaxt işə düşmür.
     *
     * Ssenari: plan 1 000 ₼, faktiki xərc 3 500 ₼ (xərc + satınalma).
     * Gözlənilən: büdcə aşımı (fakt 3 500 > plan 1 000).
     * Faktiki: `budget_fact` = null → aşım aşkarlanmır.
     */
    public function test_budget_fact_is_never_recalculated_from_real_spending(): void
    {
        $this->inStudio(fn () => $this->studio->project->forceFill(['budget_plan' => 1000])->save());

        $this->expense(1500);
        $this->purchaseOrder(2000);

        $project = $this->project();
        $r = $this->svc()->forProject($project);

        // Faktiki xərcləmə hesabatda var:
        $this->assertSame(3500.0, $r['cost'], 'Real xərc 1 500 + 2 000 = 3 500.');
        // …amma büdcə (fakt) sahəsi toxunulmamış qalır:
        $this->assertNull($project->budget_fact, '«Büdcə (fakt)» əl ilə doldurulan sahədir, xərcdən hesablanmır.');

        // Rule-30 sorğusu ilə eyni şərt — heç bir layihə tapılmır:
        $overrun = $this->inStudio(fn () => Project::query()
            ->whereNotNull('budget_plan')->where('budget_plan', '>', 0)
            ->whereColumn('budget_fact', '>', 'budget_plan')->count());
        $this->assertSame(0, $overrun, 'Büdcəsi 3.5 dəfə aşılmış layihə aşım siyahısına düşmür.');
    }

    /** Plan büdcə hesabatda `projected_revenue` kimi düzgün görünür. */
    public function test_budget_plan_feeds_projected_revenue(): void
    {
        $this->inStudio(fn () => $this->studio->project->forceFill(['budget_plan' => 12500.50])->save());

        $r = $this->svc()->forProject($this->project());

        $this->assertSame(12500.50, $r['projected_revenue']);
    }

    /** Smeta planı ilə faktiki ödənişin fərqi — borc düsturu. */
    public function test_project_debt_equals_approved_scope_minus_collected(): void
    {
        // StudioWorld-dəki smeta sətri: 10 × (50 + 20) = 700.00 (razılaşdırılmamış)
        $line = $this->studio->budgetLine->fresh();
        $this->assertSame('700.00', (string) $line->total);

        // Razılaşdırılmamış sətir borca DÜŞMÜR:
        $this->inStudio(fn () => app(ProjectFinanceService::class)
            ->recalculateDebt($this->project()));
        $this->assertSame('0.00', (string) $this->project()->debt);

        // Razılaşdırıldıqdan sonra borc = 700; 300 ödəniş sonra 400 qalır.
        $this->inStudio(function () use ($line) {
            $line->forceFill(['approval_status' => 'approved'])->save();
            $this->studio->project->payments()->create([
                'title' => 'Hissəvi', 'amount' => 300, 'status' => 'paid', 'paid_at' => now(),
            ]);
            app(ProjectFinanceService::class)->recalculateDebt($this->project());
        });

        $this->assertSame('400.00', (string) $this->project()->debt, '700 − 300 = 400.00');
    }

    // =====================================================================
    // 7. İXRAC (CSV)
    // =====================================================================

    /** Smeta ixracı: BOM, `;` ayırıcı, düstur qoruması, doğru cəm sətri. */
    public function test_estimate_csv_export_is_safe_and_totals_correctly(): void
    {
        $this->inStudio(function (): void {
            // Adı düstur kimi başlayan sətir — müştəriyə gedən faylda icra olunmamalıdır.
            $this->studio->project->budgetLines()->create([
                'work_type' => '=HYPERLINK("http://zerer.az","klik")',
                'unit' => 'm2', 'qty' => 2, 'work_price' => 100, 'material_price' => 50,
                'position' => 5, 'visible_to_client' => true,
            ]);
        });

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate.export', $this->studio->project));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM yoxdur — Excel «ə, ğ, ş» hərflərini korlayır.');
        $this->assertStringContainsString(';Otaq;"Ölçü vahidi";Həcm;', $csv, 'Ayırıcı `;` olmalıdır.');
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'Düstur xanası tək dırnaqla qorunmalıdır.');

        // Cəm: StudioWorld sətri 10 × (50+20) = 700.00 + yeni sətir 2 × (100+50) = 300.00 → 1000.00
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString('1000.00', end($lines), 'İxracın cəm sətri 700 + 300 = 1 000.00 olmalıdır.');
    }

    /** Komplektasiya ixracı: eyni qorumalar + endirimli cəm sətirləri. */
    public function test_procurement_csv_export_is_safe_and_totals_correctly(): void
    {
        $this->inStudio(function (): void {
            $this->studio->procurementItem->forceFill(['visible_to_client' => true])->save();
            $this->studio->project->procurementItems()->create([
                'name' => '@SUM(A1:A9)', 'qty' => 2, 'price' => 400,
                'discount_percent' => 25, 'purchase_status' => 'planned',
                'visible_to_client' => true,
            ]);
        });

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.export', $this->studio->project));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'@SUM(A1:A9)", $csv, 'Düstur xanası qorunmalıdır.');

        // Divan 1 × 1 200 = 1 200.00 ; yeni sətir 2 × 400 = 800.00 → endirimsiz 2 000.00
        // Endirimlə: 1 200 + (800 − 25%) = 1 200 + 600 = 1 800.00
        $this->assertStringContainsString('"Endirimdən əvvəl";2000.00', $csv);
        $this->assertStringContainsString(';Endirimlə;1800.00', $csv);
    }

    /**
     * QA TAPINTI [KİÇİK] — MƏNFİ MƏBLƏĞ İXRACDA MƏTNƏ ÇEVRİLİR.
     *
     * `Csv::cell()` (Csv.php:28) `-` ilə başlayan xananı düstur sayıb tək dırnaq
     * qoyur. Bu, təhlükəsizlik üçün doğrudur, amma mənfi pul dəyəri
     * (`-150.00`) Excel-də MƏTN kimi açılır və sütun cəmlənmir.
     * Tövsiyə: rəqəm formatlı dəyəri istisna etmək (`/^-?\d+([.,]\d+)?$/`).
     */
    public function test_a_negative_money_cell_becomes_text_in_excel(): void
    {
        $this->assertSame("'-150.00", Csv::cell('-150.00'),
            'Mənfi məbləğ mətnə çevrilir — Excel-də sütun cəmi işləmir.');
        $this->assertSame('150.00', Csv::cell('150.00'));
    }

    /**
     * QA TAPINTI [ORTA] — RENTABELLİK HESABATININ İXRACI YOXDUR.
     *
     * `Csv::` bütün `app/` boyu YALNIZ iki yerdə istifadə olunur
     * (Portal/EstimateController, Portal/ProcurementController). Rəhbərin əsas
     * maliyyə ekranı (`Filament/Pages/Profitability.php`) heç bir ixrac düyməsi
     * vermir — rəqəmləri mühasibə ötürmək üçün ekrandan köçürmək lazımdır.
     */
    public function test_the_profitability_report_has_no_export_at_all(): void
    {
        $page = file_get_contents(app_path('Filament/Pages/Profitability.php'));
        $widget = file_get_contents(app_path('Filament/Widgets/ProfitabilityWidget.php'));

        $this->assertStringNotContainsString('Csv', $page);
        $this->assertStringNotContainsString('Export', $page);
        $this->assertStringNotContainsString('Csv', $widget);
        $this->assertStringNotContainsString('ExportAction', $widget,
            'Rentabellik cədvəlində ixrac düyməsi yoxdur.');
    }

    // =====================================================================
    // 8. TENANT İZOLYASİYASI — RƏQƏMLƏRDƏ
    // =====================================================================

    /** İkinci studiyanın pulu birincinin portfelinə, proqnozuna qarışmır. */
    public function test_a_second_studios_money_never_enters_the_first_studios_report(): void
    {
        $beta = StudioWorld::make('beta');

        app(TenantContext::class)->actingAs($beta->tenant->id, function () use ($beta): void {
            $beta->project->payments()->create([
                'title' => 'Beta ödəniş', 'amount' => 77777, 'status' => 'paid', 'paid_at' => now(),
            ]);
            $beta->project->payments()->create([
                'title' => 'Beta gözləyən', 'amount' => 55555, 'status' => 'pending',
                'due_date' => today()->addDays(5),
            ]);
            Expense::create([
                'project_id' => $beta->project->id, 'category' => 'material',
                'amount' => 8888, 'date' => today(), 'status' => 'approved',
            ]);
            Invoice::create([
                'project_id' => $beta->project->id, 'client_id' => $beta->client->id,
                'number' => 'BETA-1', 'total' => 44444, 'paid_amount' => 0,
                'status' => 'sent', 'issue_date' => today(), 'due_date' => today()->subDays(9),
            ]);
        });

        // Alfa studiyasının öz rəqəmləri:
        $this->pay(1000);
        $this->pay(250, 'pending', today()->addDays(3)->toDateString());
        $this->expense(400);
        $this->invoice(['total' => 600, 'paid_amount' => 0, 'due_date' => today()->subDays(2)]);

        $p = $this->inStudio(fn () => $this->svc()->portfolio());
        $f = $this->inStudio(fn () => $this->svc()->cashForecast());

        $this->assertSame(1000.0, $p['collected'], 'Beta studiyasının 77 777 ₼-i alfa portfelinə düşdü.');
        $this->assertSame(400.0, $p['cost'], 'Beta xərci alfa maya dəyərinə düşdü.');
        $this->assertSame(600.0, $p['receivable'], 'Beta fakturası alfa debitor borcuna düşdü.');
        $this->assertSame(600.0, $p['overdue']);
        // 250 (bu test) + 500 (StudioWorld avansı) = 750; beta-nın 55 555-i yoxdur.
        $this->assertSame(750.0, $f['d30'], 'Beta gözləyən ödənişi alfa proqnozuna düşdü.');

        // Əks istiqamət: beta öz rəqəmlərini görür, alfa-nınkını yox.
        $betaPortfolio = app(TenantContext::class)->actingAs($beta->tenant->id, fn () => $this->svc()->portfolio());
        $this->assertSame(77777.0, $betaPortfolio['collected']);
        $this->assertSame(8888.0, $betaPortfolio['cost']);
    }

    /** Layihə səviyyəsində də sızma yoxdur. */
    public function test_per_project_profitability_is_tenant_scoped(): void
    {
        $beta = StudioWorld::make('beta2');

        app(TenantContext::class)->actingAs($beta->tenant->id, fn () => $beta->project->payments()->create([
            'title' => 'Beta', 'amount' => 31337, 'status' => 'paid', 'paid_at' => now(),
        ]));

        $this->pay(120);

        $alpha = $this->svc()->forProject($this->project());
        $this->assertSame(120.0, $alpha['revenue']);

        $betaResult = app(TenantContext::class)->actingAs(
            $beta->tenant->id,
            fn () => (new ProfitabilityService)->forProject($beta->project)
        );
        $this->assertSame(31337.0, $betaResult['revenue']);
    }

    // =====================================================================
    // 9. MAYA DƏYƏRİ SƏBƏTLƏRİ — STATUS FİLTRLƏRİ
    // =====================================================================

    /** Rədd edilmiş xərc maya dəyərinə düşmür; qalan üç status düşür. */
    public function test_expense_status_filter_matches_the_documented_rule(): void
    {
        $this->expense(100, ExpenseStatus::Pending);
        $this->expense(200, ExpenseStatus::Approved);
        $this->expense(400, ExpenseStatus::Paid);
        $this->expense(800, ExpenseStatus::Rejected);

        $r = $this->svc()->forProject($this->project());

        // 100 + 200 + 400 = 700.00 ; rədd edilən 800 sayılmır
        $this->assertSame(700.0, $r['expenses']);
    }

    /** Qaralama və ləğv edilmiş satınalma sifarişi maya dəyəri deyil. */
    public function test_purchase_order_status_filter_matches_the_documented_rule(): void
    {
        $this->purchaseOrder(1000, 0, PurchaseOrderStatus::Draft->value);
        $this->purchaseOrder(2000, 0, PurchaseOrderStatus::Ordered->value);
        $this->purchaseOrder(3000, 0, PurchaseOrderStatus::Received->value);
        $this->purchaseOrder(4000, 0, PurchaseOrderStatus::Cancelled->value);

        $r = $this->svc()->forProject($this->project());

        // 2 000 + 3 000 = 5 000.00
        $this->assertSame(5000.0, $r['purchases']);
    }

    /**
     * Müştəriyə fakturalanmış, amma maya dəyəri yazılmamış komplektasiya
     * bayraqlanır — «pulsuz mebel» marjası.
     */
    public function test_billed_procurement_with_no_recorded_cost_is_flagged(): void
    {
        $this->pay(5000);
        $this->inStudio(fn () => $this->studio->procurementItem
            ->forceFill(['approval_status' => 'approved'])->save());

        $r = $this->svc()->forProject($this->project());

        // Divan 1 × 1 200 = 1 200.00 müştəriyə gedib, xərc kimi heç nə yoxdur.
        $this->assertSame(1200.0, $r['uncosted_procurement']);
        $this->assertSame(100.0, $r['margin'], 'Maya dəyəri yazılmadığı üçün marja 100% görünür — bayraq bunu üzə çıxarır.');
    }

    /**
     * QA TAPINTI [ORTA] — `uncosted_procurement` BİR QƏPİK XƏRC GÖRƏN KİMİ SÖNÜR.
     *
     * Şərt `$purchases > 0 || $expenses > 0` (ProfitabilityService.php:70) —
     * yəni layihədə 1 ₼-lıq taksi xərci olsa, 1 200 ₼-lıq fakturalanmış
     * mebelin maya dəyərsiz qaldığı barədə xəbərdarlıq tam itir.
     *
     * Gözlənilən: xəbərdarlıq qalır (1 ₼ 1 200 ₼-ı örtmür).
     * Faktiki: 0.00 — bayraq sönür.
     */
    public function test_one_manat_of_expense_silences_the_uncosted_procurement_warning(): void
    {
        $this->pay(5000);
        $this->inStudio(fn () => $this->studio->procurementItem
            ->forceFill(['approval_status' => 'approved'])->save());
        $this->expense(1);

        $r = $this->svc()->forProject($this->project());

        $this->assertSame(0.0, $r['uncosted_procurement'],
            '1 ₼ xərc 1 200 ₼-lıq maya dəyərsiz komplektasiya xəbərdarlığını söndürdü.');
    }

    /**
     * QA TAPINTI [ORTA] — LAYİHƏSİZ (ÜMUMSTUDİYA) XƏRC CƏDVƏLDƏ GÖRÜNMÜR.
     *
     * `portfolio()` `project_id IS NULL` xərcləri qəsdən sayır (ofis icarəsi),
     * `ProfitabilityWidget` cədvəli isə yalnız layihə sətirlərindən ibarətdir.
     * Ona görə kartdakı «Xərc» rəqəmi cədvəl sətirlərinin cəmindən böyükdür və
     * ekranda bu fərqin izahı yoxdur (kartın alt yazısı yalnız «bütün dövr»
     * deyir, «+ layihəsiz xərclər» demir).
     *
     * Ssenari: layihə xərci 300 ₼ + ofis icarəsi 2 000 ₼.
     * Cədvəl cəmi: 300.00 ; Kart: 2 300.00 — fərq 2 000.00 izahsızdır.
     */
    public function test_overhead_expenses_appear_in_the_card_but_not_in_the_table(): void
    {
        $this->expense(300);
        $this->inStudio(fn () => Expense::create([
            'project_id' => null, 'category' => 'other', 'vendor' => 'Ofis',
            'amount' => 2000, 'date' => today(), 'status' => 'approved',
        ]));

        $p = $this->inStudio(fn () => $this->svc()->portfolio());
        $tableCost = $this->svc()->forProject($this->project())['cost']
            + $this->svc()->forProject($this->studio->otherProject)['cost'];

        $this->assertSame(2300.0, $p['cost'], 'Kart: 300 + 2 000 = 2 300.00');
        $this->assertSame(300.0, $tableCost, 'Cədvəl: yalnız 300.00');
        $this->assertNotSame($p['cost'], $tableCost, 'İki rəqəm arasındakı 2 000 ₼ fərq ekranda izah olunmur.');
    }
}
