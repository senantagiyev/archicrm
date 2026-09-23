<?php

namespace Tests\Feature\Fix;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\ProfitabilityService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA-nın tapdığı MALİYYƏ baqlarının regresiya testləri.
 *
 * Hər test bir tapıntıya uyğundur və GÖZLƏNİLƏN RƏQƏM şərhdə açıq yazılıb:
 * bu fayl «səhv bir daha qayıtmasın» sənədidir, ona görə də rəqəmlər əl ilə
 * hesablanıb, float müqayisəsi yox, `decimal(12,2)` sətir müqayisəsi edilir.
 */
class InvoiceIntegrityFixTest extends TestCase
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

    private function portfolio(): array
    {
        // Servisin daxili memo keşi var — hər ssenaridə təzə nüsxə lazımdır.
        return $this->inStudio(fn () => (new ProfitabilityService)->portfolio());
    }

    // =====================================================================
    // TAPINTI 1 — total = subtotal + tax
    // =====================================================================

    /** QA ssenarisi: 1 000 + 180, operator 500 yazıb. Gözlənilən: 1 180.00. */
    public function test_total_is_recomputed_from_subtotal_and_tax(): void
    {
        $invoice = $this->invoice(['subtotal' => 1000, 'tax' => 180, 'total' => 500]);

        $this->assertSame('1180.00', (string) $invoice->fresh()->total);
        $this->assertSame('1000.00', (string) $invoice->fresh()->subtotal);

        // Debitor borc kartı da düzgün rəqəmi göstərir: 1 180 − 0 = 1 180.00
        $this->assertSame(1180.0, $this->portfolio()['receivable']);
    }

    /** `subtotal`/`tax` sonradan dəyişəndə `total` yenidən hesablanır: 2 000 + 360 = 2 360.00 */
    public function test_editing_subtotal_or_tax_recomputes_the_total(): void
    {
        $invoice = $this->invoice(['subtotal' => 1000, 'tax' => 180]);
        $this->assertSame('1180.00', (string) $invoice->fresh()->total);

        $this->inStudio(fn () => $invoice->update(['subtotal' => 2000, 'tax' => 360]));

        $this->assertSame('2360.00', (string) $invoice->fresh()->total);
    }

    /**
     * Sətirsiz («yekun məbləğ» üslubunda) faktura: yalnız `total` yazılır,
     * `subtotal` ondan geri hesablanır ki, invariant pozulmasın.
     * 5 000 total, vergi 0 → subtotal 5 000.00, total 5 000.00.
     */
    public function test_a_lump_sum_total_backfills_the_subtotal(): void
    {
        $invoice = $this->invoice(['total' => 5000]);

        $this->assertSame('5000.00', (string) $invoice->fresh()->total);
        $this->assertSame('5000.00', (string) $invoice->fresh()->subtotal);

        // Sonradan yalnız `total` düzəldilir: 7 000 → subtotal da 7 000.00 olur.
        $this->inStudio(fn () => $invoice->update(['total' => 7000]));

        $this->assertSame('7000.00', (string) $invoice->fresh()->total);
        $this->assertSame('7000.00', (string) $invoice->fresh()->subtotal);
    }

    /** Qəpik sürüşməsi olmamalıdır: 33.33 + 6.67 = 40.00 (float 39.999… deyil). */
    public function test_totals_are_rounded_to_two_decimals(): void
    {
        $invoice = $this->invoice(['subtotal' => 33.33, 'tax' => 6.67]);

        $this->assertSame('40.00', (string) $invoice->fresh()->total);
    }

    // =====================================================================
    // TAPINTI 2 — artıq ödəniş
    // =====================================================================

    /** QA ssenarisi: 1 000-lik fakturaya 3 000 ödəniş → istisna. */
    public function test_paid_amount_cannot_exceed_the_total(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invoice(['total' => 1000, 'paid_amount' => 3000]);
    }

    /** Mövcud fakturanın `paid_amount`-unu yuxarı çəkmək də bloklanır. */
    public function test_updating_paid_amount_above_the_total_is_blocked(): void
    {
        $invoice = $this->invoice(['total' => 1000, 'paid_amount' => 0]);

        try {
            $this->inStudio(fn () => $invoice->update(['paid_amount' => 1200]));
            $this->fail('Artıq ödəniş qəbul edildi — istisna gözlənilirdi.');
        } catch (\RuntimeException) {
            // gözlənilən
        }

        $this->assertSame('0.00', (string) $invoice->fresh()->paid_amount);
    }

    /**
     * QA ssenarisinin maliyyə nəticəsi: A 1 000 (tam ödənilib) + B 5 000
     * (ödənilməyib). Gözlənilən debitor borc: 5 000.00 — artıq ödəniş artıq
     * yazıla bilmədiyi üçün B-nin borcunu «yeyən» mənfi qalıq yaranmır.
     */
    public function test_receivable_is_not_eaten_by_an_overpaid_invoice(): void
    {
        $this->invoice(['total' => 1000, 'paid_amount' => 1000, 'number' => 'A']);
        $this->invoice(['total' => 5000, 'paid_amount' => 0, 'number' => 'B']);

        $this->assertSame(5000.0, $this->portfolio()['receivable']);
    }

    /** Sərhəd: `paid_amount` = `total` (tam ödəniş) keçməlidir. */
    public function test_paid_amount_equal_to_total_is_allowed(): void
    {
        $invoice = $this->invoice(['total' => 1000, 'paid_amount' => 1000]);

        $this->assertSame('1000.00', (string) $invoice->fresh()->paid_amount);
    }

    // =====================================================================
    // TAPINTI 3 — mənfi məbləğlər
    // =====================================================================

    /** QA ssenarisi: −4 000-lik faktura → istisna (Expense üslubu). */
    public function test_a_negative_invoice_total_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invoice(['total' => -4000]);
    }

    public function test_a_negative_subtotal_or_tax_is_rejected(): void
    {
        try {
            $this->invoice(['subtotal' => 100, 'tax' => -50, 'number' => 'NEG-TAX']);
            $this->fail('Mənfi vergi qəbul edildi.');
        } catch (\RuntimeException) {
            // gözlənilən
        }

        $this->expectException(\RuntimeException::class);
        $this->invoice(['subtotal' => -100, 'number' => 'NEG-SUB']);
    }

    public function test_a_negative_paid_amount_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invoice(['total' => 1000, 'paid_amount' => -100]);
    }

    /**
     * QA ssenarisinin maliyyə nəticəsi: 5 000-lik faktura + mənfi faktura
     * cəhdi. Gözlənilən debitor borc: 5 000.00 (mənfi sətir bazaya düşmür).
     */
    public function test_receivable_is_not_reduced_by_a_negative_invoice(): void
    {
        $this->invoice(['total' => 5000, 'paid_amount' => 0]);

        try {
            $this->invoice(['total' => -4000, 'paid_amount' => 0]);
        } catch (\RuntimeException) {
            // gözlənilən — sətir yaradılmır
        }

        $this->assertSame(5000.0, $this->portfolio()['receivable']);
        $this->assertSame(1, Invoice::withoutGlobalScopes()->count());
    }

    /** QA ssenarisi: 1 000 gəlir + (−400) ödəniş → gözlənilən gəlir 1 000.00. */
    public function test_a_negative_payment_is_rejected_and_revenue_stays(): void
    {
        $this->inStudio(fn () => $this->studio->project->payments()->create([
            'title' => 'Ödəniş', 'amount' => 1000, 'status' => 'paid', 'paid_at' => now(),
        ]));

        try {
            $this->inStudio(fn () => $this->studio->project->payments()->create([
                'title' => 'Mənfi', 'amount' => -400, 'status' => 'paid', 'paid_at' => now(),
            ]));
            $this->fail('Mənfi ödəniş qəbul edildi — istisna gözlənilirdi.');
        } catch (\RuntimeException) {
            // gözlənilən
        }

        $revenue = $this->inStudio(
            fn () => (new ProfitabilityService)->forProject($this->studio->project->fresh())['revenue']
        );

        $this->assertSame(1000.0, $revenue);
    }

    /** Sıfır məbləğli ödəniş də maliyyə hadisəsi deyil — bloklanır. */
    public function test_a_zero_payment_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->inStudio(fn () => Payment::create([
            'project_id' => $this->studio->project->id,
            'title' => 'Sıfır', 'amount' => 0, 'status' => 'pending',
        ]));
    }

    // =====================================================================
    // TAPINTI 4 — status ödənişi izləyir
    // =====================================================================

    /** QA ssenarisi: 1 000-lik faktura, 0 → 400 → 1 000. */
    public function test_status_follows_the_paid_amount(): void
    {
        $invoice = $this->invoice(['total' => 1000, 'paid_amount' => 0]);

        // 0/1 000 — ödəniş yoxdur, «Göndərilib» qalır.
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);

        // 400/1 000 → «Qismən ödənilib»
        $this->inStudio(fn () => $invoice->update(['paid_amount' => 400]));
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        // 1 000/1 000 → «Ödənilib»
        $this->inStudio(fn () => $invoice->update(['paid_amount' => 1000]));
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        // Ödəniş geri götürülüb → yenidən ödənilməmiş
        $this->inStudio(fn () => $invoice->update(['paid_amount' => 0]));
        $this->assertSame(InvoiceStatus::Sent, $invoice->fresh()->status);
    }

    /** Tam ödənilmiş faktura «Vaxtı keçib» statusundan da çıxır. */
    public function test_a_fully_paid_overdue_invoice_becomes_paid(): void
    {
        $invoice = $this->invoice([
            'total' => 2000, 'paid_amount' => 0,
            'status' => InvoiceStatus::Overdue->value,
            'due_date' => today()->subDays(10),
        ]);

        $this->inStudio(fn () => $invoice->update(['paid_amount' => 2000]));

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        // Borc da bağlanır: 2 000 − 2 000 = 0.00
        $this->assertSame(0.0, $this->portfolio()['receivable']);
        $this->assertSame(0.0, $this->portfolio()['overdue']);
    }

    /** Qismən ödəniş «Vaxtı keçib» statusunu ƏZMİR — gecikmə davam edir. */
    public function test_a_partially_paid_overdue_invoice_stays_overdue(): void
    {
        $invoice = $this->invoice([
            'total' => 2000, 'paid_amount' => 0,
            'status' => InvoiceStatus::Overdue->value,
            'due_date' => today()->subDays(10),
        ]);

        $this->inStudio(fn () => $invoice->update(['paid_amount' => 500]));

        $this->assertSame(InvoiceStatus::Overdue, $invoice->fresh()->status);
        // Gecikmiş qalıq: 2 000 − 500 = 1 500.00
        $this->assertSame(1500.0, $this->portfolio()['overdue']);
    }

    /** `draft` avtomatik keçidə düşmür — qaralama hələ kəsilməyib. */
    public function test_a_draft_invoice_status_is_never_touched(): void
    {
        $invoice = $this->invoice([
            'total' => 1000, 'paid_amount' => 0,
            'status' => InvoiceStatus::Draft->value,
        ]);

        $this->inStudio(fn () => $invoice->update(['paid_amount' => 1000]));

        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    /** `cancelled` də toxunulmazdır — ləğv şüurlu qərardır. */
    public function test_a_cancelled_invoice_status_is_never_touched(): void
    {
        $invoice = $this->invoice([
            'total' => 1000, 'paid_amount' => 0,
            'status' => InvoiceStatus::Cancelled->value,
        ]);

        $this->inStudio(fn () => $invoice->update(['paid_amount' => 1000]));

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        // Ləğv edilmiş faktura nə borca, nə gecikməyə düşür.
        $this->assertSame(0.0, $this->portfolio()['receivable']);
    }
}
