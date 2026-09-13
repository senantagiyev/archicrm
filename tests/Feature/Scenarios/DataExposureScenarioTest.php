<?php

namespace Tests\Feature\Scenarios;

use App\Enums\ApprovalStatus;
use App\Models\SpecificationItem;
use App\Services\Approvals\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Scenario: the wrong person opens the right URL. Each test below is one thing a
 * studio would consider a leak — a role reading money it has no domain for, a
 * client seeing a line marked internal, a deletion lock that a checkbox undoes.
 */
class DataExposureScenarioTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('sizma');
    }

    public function test_a_visualizer_cannot_browse_the_studio_wide_approval_list(): void
    {
        $status = $this->actingAs($this->studio->user('visualizer'))
            ->get(route('filament.app.resources.approvals.index'))
            ->status();

        $this->assertContains(
            $status,
            [403, 404],
            'Vizualizator bütün razılaşdırmaların siyahısını açdı — matrisdə Smeta və Komplektasiya = Yoxdur.',
        );
    }

    public function test_a_visualizer_does_not_see_studio_revenue_on_the_dashboard(): void
    {
        $this->studio->payment->forceFill([
            'status' => 'paid',
            'paid_at' => now(),
            'amount' => 987654.32,
        ])->save();

        $response = $this->actingAs($this->studio->user('visualizer'))
            ->get(route('filament.app.pages.dashboard'));

        if (in_array($response->status(), [403, 404], true)) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertStringNotContainsStringQuietly(
            '987 654',
            $response->getContent(),
            'Vizualizator idarə panelində studiyanın aylıq gəlirini gördü (Ödənişlər = Yoxdur).',
        );
    }

    public function test_an_accountant_cannot_read_the_client_brief(): void
    {
        $status = $this->actingAs($this->studio->user('accountant'))
            ->get(route('filament.app.resources.projects.brief-review', ['record' => $this->studio->project->id]))
            ->status();

        $this->assertContains(
            $status,
            [403, 404],
            'Mühasib brif icmalını açdı — matrisdə Brif = Yoxdur.',
        );
    }

    public function test_a_visualizer_has_no_rights_over_specifications(): void
    {
        $viz = $this->studio->user('visualizer');

        $this->assertFalse(
            $viz->can('viewAny', SpecificationItem::class),
            'Vizualizator spesifikasiyaları görə bilir — matrisdə Komplektasiya = Yoxdur.',
        );
        $this->assertFalse(
            $viz->can('create', SpecificationItem::class),
            'Vizualizator spesifikasiya yarada bilir.',
        );
    }

    /**
     * `visible_to_client = false` is the studio's "the client must never see this
     * line" switch. The portal approvals view prints `subjectLabel()` and the
     * line total verbatim, so the flag has to be honoured before the approval is
     * ever created — the route itself is not asserted here because it uses the
     * MySQL-only `field()` function and cannot run under the sqlite test driver.
     */
    public function test_a_budget_line_marked_internal_is_never_sent_to_the_client(): void
    {
        $internalLine = $this->studio->project->budgetLines()->create([
            'work_type' => 'DAXILI-MARJA-SETRI',
            'unit' => 'ədəd', 'qty' => 1, 'work_price' => 9999, 'position' => 2,
            'visible_to_client' => false,
        ]);

        try {
            app(ApprovalService::class)->request($internalLine, $this->studio->user('project_manager'));

            $this->fail('Müştəriyə gizli smeta sətri razılaşdırmaya göndərildi (visible_to_client nəzərə alınmır).');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('gizli', $e->getMessage());
        }

        $this->assertDatabaseMissing('approvals', [
            'approvable_type' => 'budget_line',
            'approvable_id' => $internalLine->id,
        ]);
    }

    /**
     * The customer's approvals page is the one portal screen the test suite can
     * never execute: `orderByRaw("field(status, 'pending') desc")` is MySQL-only
     * and 500s under sqlite. It works in production, but it ships untested.
     */
    public function test_the_portal_approvals_page_can_run_on_the_test_database(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.approvals', $this->studio->project->id));

        $this->assertNotSame(
            500,
            $response->status(),
            'Portal razılaşdırmalar səhifəsi test bazasında çökür (MySQL-ə xas field() funksiyası) — bu səhifə heç vaxt test edilə bilmir.',
        );
    }

    public function test_an_approved_and_paid_procurement_item_cannot_be_unlocked_by_clearing_paid(): void
    {
        $item = $this->studio->procurementItem;
        $item->forceFill(['approval_status' => ApprovalStatus::Approved->value, 'paid' => true])->save();

        $this->assertTrue($item->fresh()->isDeletionLocked(), 'Ilkin şərt: pozisiya kilidli olmalıdır.');

        // Clearing `paid` was the way around the lock; it must now be refused.
        try {
            $item->update(['paid' => false]);

            $this->fail('«Ödənilib» işarəsi geri alındı — TZ §5.10 kilidi keçilə bilir.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('geri alına bilməz', $e->getMessage());
        }

        $this->assertTrue($item->fresh()->isDeletionLocked(), 'Pozisiya kilidli qalmalıdır.');

        try {
            $item->fresh()->delete();

            $this->fail('Razılaşdırılmış və ödənilmiş komplektasiya silindi.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('silinə bilməz', $e->getMessage());
        }
    }

    public function test_a_designer_does_not_see_payment_amounts_in_the_calendar_feed(): void
    {
        $this->studio->payment->forceFill(['amount' => 123456.78, 'due_date' => now()->addDays(3)])->save();

        $response = $this->actingAs($this->studio->user('designer'))
            ->getJson(route('calendar.events', [
                'start' => now()->subWeek()->toDateString(),
                'end' => now()->addMonth()->toDateString(),
            ]));

        $response->assertOk();

        $this->assertStringNotContainsStringQuietly(
            '123 456',
            $response->getContent(),
            'Dizayner təqvimdə ödəniş məbləğini gördü — matrisdə Ödənişlər = Yoxdur.',
        );
    }
}
