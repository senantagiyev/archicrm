<?php

namespace Tests\Feature\Scenarios;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\TimeEntry;
use App\Services\Finance\ProfitabilityService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Scenario: the owner opens Rentabellik and decides what to charge and whom to
 * hire. Every figure on that screen has to be arithmetic anyone can redo by
 * hand — these tests do exactly that, with numbers chosen so the expected
 * result is obvious.
 */
class FinanceCalculationScenarioTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('maliyye');
    }

    private function inStudio(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    private function payProject(float $amount): void
    {
        $this->inStudio(fn () => $this->studio->project->payments()->create([
            'title' => 'Ödəniş', 'amount' => $amount, 'status' => 'paid', 'paid_at' => now(),
        ]));
    }

    private function logHours(float $hours, float $hourlyCost): TimeEntry
    {
        $designer = $this->studio->user('designer');
        $designer->forceFill(['hourly_internal_cost' => $hourlyCost])->save();

        return $this->inStudio(fn () => TimeEntry::create([
            'user_id' => $designer->id,
            'project_id' => $this->studio->project->id,
            'duration_minutes' => (int) round($hours * 60),
            'source' => 'manual',
        ]));
    }

    private function expense(float $amount, ExpenseStatus $status): Expense
    {
        return $this->inStudio(fn () => Expense::create([
            'project_id' => $this->studio->project->id,
            'category' => 'other',
            'vendor' => 'Təchizatçı',
            'amount' => $amount,
            'date' => today(),
            'status' => $status->value,
            'created_by_user_id' => $this->studio->user('accountant')->id,
        ]));
    }

    public function test_labour_cost_is_minutes_times_the_hourly_rate(): void
    {
        $this->logHours(hours: 7.5, hourlyCost: 20);

        $result = app(ProfitabilityService::class)->forProject($this->studio->project);

        // 7.5 saat × 20 ₼ = 150.00
        $this->assertSame(150.0, $result['labor_cost'], 'Əmək dəyəri saat × tarif düsturuna uyğun gəlmir.');
    }

    public function test_the_cost_rate_is_snapshotted_so_a_later_raise_does_not_rewrite_history(): void
    {
        $entry = $this->logHours(hours: 10, hourlyCost: 15);

        $this->assertSame('15.00', (string) $entry->fresh()->hourly_cost_snapshot);

        // The designer gets a raise; last month's work must keep its old cost.
        $this->studio->user('designer')->forceFill(['hourly_internal_cost' => 40])->save();

        $result = app(ProfitabilityService::class)->forProject($this->studio->project->fresh());

        $this->assertSame(150.0, $result['labor_cost'], 'Tarif artımı keçmiş saatların dəyərini geriyə dönük dəyişdi.');
    }

    /**
     * A rejected expense is money the studio decided NOT to spend. Counting it as
     * cost understates every margin on the report.
     */
    public function test_a_rejected_expense_is_not_counted_as_cost(): void
    {
        $this->payProject(1000);
        $this->expense(200, ExpenseStatus::Approved);
        $this->expense(500, ExpenseStatus::Rejected);

        $result = app(ProfitabilityService::class)->forProject($this->studio->project);

        $this->assertSame(
            200.0,
            $result['expenses'],
            'Rədd edilmiş xərc maya dəyərinə daxil edildi — mənfəət olduğundan az göstərilir.',
        );
        $this->assertSame(800.0, $result['gross_profit'], '1000 gəlir − 200 xərc = 800 olmalıdır.');
        $this->assertSame(80.0, $result['margin'], 'Marja 80% olmalıdır.');
    }

    public function test_the_portfolio_snapshot_also_ignores_rejected_expenses(): void
    {
        $this->payProject(1000);
        $this->expense(200, ExpenseStatus::Approved);
        $this->expense(500, ExpenseStatus::Rejected);

        $portfolio = $this->inStudio(fn () => app(ProfitabilityService::class)->portfolio());

        $this->assertSame(200.0, $portfolio['cost'], 'Portfel maya dəyəri rədd edilmiş xərci sayır.');
        $this->assertSame(800.0, $portfolio['gross_profit']);
    }

    /**
     * A project that cost money and collected nothing is a 100% loss, not a 0%
     * margin. 0% reads as "broke even" on the report.
     */
    public function test_a_project_with_cost_and_no_revenue_is_not_reported_as_zero_margin(): void
    {
        $this->expense(750, ExpenseStatus::Approved);

        $result = app(ProfitabilityService::class)->forProject($this->studio->project);

        $this->assertSame(-750.0, $result['gross_profit'], 'Gəlirsiz layihədə zərər mənfi göstərilməlidir.');
        $this->assertNull(
            $result['margin'],
            'Gəliri sıfır, xərci 750 olan layihə «0% marja» kimi göstərilir — bu, zərərsizlik kimi oxunur.',
        );
    }

    /**
     * `projected` deliberately excludes archived projects. If `collected` and
     * `cost` include them, margin is a ratio of two different populations.
     */
    public function test_archived_projects_are_treated_consistently_across_the_portfolio(): void
    {
        $this->payProject(1000);
        $this->expense(100, ExpenseStatus::Approved);

        $live = $this->inStudio(fn () => app(ProfitabilityService::class)->portfolio());

        $this->studio->project->delete();

        $afterArchive = $this->inStudio(fn () => app(ProfitabilityService::class)->portfolio());

        $this->assertNotSame(
            $live['collected'],
            $afterArchive['collected'],
            'Arxivlənmiş layihənin gəliri portfeldə qalır, planlaşdırılan gəlir isə çıxarılır — marja iki fərqli toplum üzərindən hesablanır.',
        );
    }

    public function test_money_from_another_studio_never_enters_the_portfolio(): void
    {
        $other = StudioWorld::make('qonsu');

        app(TenantContext::class)->actingAs($other->tenant->id, fn () => $other->project->payments()->create([
            'title' => 'Qonşu ödəniş', 'amount' => 99999, 'status' => 'paid', 'paid_at' => now(),
        ]));

        $this->payProject(500);

        $portfolio = $this->inStudio(fn () => app(ProfitabilityService::class)->portfolio());

        $this->assertSame(500.0, $portfolio['collected'], 'Portfeldə başqa studiyanın pulu göründü.');
    }

    public function test_totals_do_not_drift_when_many_small_amounts_are_summed(): void
    {
        $this->payProject(0.10);

        for ($i = 0; $i < 3; $i++) {
            $this->expense(0.01, ExpenseStatus::Approved);
        }

        $result = app(ProfitabilityService::class)->forProject($this->studio->project);

        $this->assertSame(0.03, $result['expenses'], 'Kiçik məbləğlərin cəmi qəpik səviyyəsində sürüşür.');
        $this->assertSame(0.07, $result['gross_profit'], '0.10 − 0.03 = 0.07 olmalıdır.');
    }

    public function test_hours_logged_by_someone_with_no_cost_rate_are_not_silently_free(): void
    {
        // The column defaults to 0, so "no rate set" is the state a studio is in
        // before anyone fills in cost rates — i.e. the common case on day one.
        $visualizer = $this->studio->user('visualizer');
        $visualizer->forceFill(['hourly_internal_cost' => 0])->save();

        $this->inStudio(fn () => TimeEntry::create([
            'user_id' => $visualizer->id,
            'project_id' => $this->studio->project->id,
            'duration_minutes' => 600,
            'source' => 'manual',
        ]));

        $result = app(ProfitabilityService::class)->forProject($this->studio->project);

        // Not an assertion about the number — about the report being honest that
        // 10 hours of work carry no cost, rather than quietly reporting 100% margin.
        $this->assertSame(0.0, $result['labor_cost']);
        $this->assertArrayHasKey(
            'uncosted_minutes',
            $result,
            'Tarifi təyin olunmamış işçinin saatları maya dəyərinə düşmür və hesabatda bu heç cür bildirilmir.',
        );
    }
}
