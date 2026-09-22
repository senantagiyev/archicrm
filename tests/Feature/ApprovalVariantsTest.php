<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Services\Approvals\ApprovalService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Roomix-in razılaşdırma kartı: versiya · variantlar · cavab müddəti.
 *
 * Ən həssas yer variant seçimidir — açar müştəridən gəlir, yəni siyahıda
 * olmayan dəyər qəbul edilsə, dizaynerə heç vaxt təklif etmədiyi variant
 * «seçilmiş» kimi görünərdi.
 */
class ApprovalVariantsTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private ApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('approval-variants');
        $this->service = app(ApprovalService::class);
    }

    private function request(array $variants = []): Approval
    {
        return app(TenantContext::class)->actingAs(
            $this->studio->tenant->id,
            fn () => $this->service->request(
                // Fresh nüsxə: layihə münasibəti sətrin üstündə keşlənir, yəni
                // testdə layihəni dəyişəndən sonra köhnə dəyər gələrdi.
                $this->studio->budgetLine->fresh(),
                $this->studio->user('owner'),
                null,
                $variants,
            ),
        );
    }

    public function test_each_resubmission_raises_the_version_and_keeps_the_previous_round(): void
    {
        // StudioWorld fixture-u bu sətir üçün artıq bir razılaşdırma yaradır,
        // ona görə başlanğıc nömrə sabit deyil — artımı ölçürük.
        $first = $this->request();
        $second = $this->request();

        $this->assertSame($first->version + 1, $second->version);
        $this->assertSame(ApprovalStatus::Draft, $first->fresh()->status, 'Köhnə sorğu sıradan çıxmalıdır.');
        $this->assertTrue($second->history()->get()->contains('id', $first->id), 'Tarixçə əvvəlki dövrü saxlamalıdır.');
    }

    /** Cavab müddəti layihənin öz pəncərəsindən götürülür. */
    public function test_the_respond_by_date_follows_the_projects_response_window(): void
    {
        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $this->studio->project->update(['client_response_days' => 7]);
        });

        $approval = $this->request();

        $this->assertSame(
            now()->addDays(7)->toDateString(),
            $approval->respond_by->toDateString(),
        );
    }

    public function test_choosing_a_variant_records_it_on_the_approval(): void
    {
        $approval = $this->request([
            ['key' => 'warm_wood', 'label' => 'Arka və isti ağac'],
            ['key' => 'dark_panels', 'label' => 'Divar panelləri və tünd tekstil'],
        ]);

        $this->assertTrue($approval->hasVariants());

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), [
                'decision' => 'approve',
                'variant' => 'dark_panels',
            ])
            ->assertRedirect();

        $approval->refresh();

        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertSame('dark_panels', $approval->chosen_variant);
    }

    /** Siyahıda olmayan açar qəbul edilməməlidir. */
    public function test_an_unknown_variant_key_is_rejected(): void
    {
        $approval = $this->request([
            ['key' => 'a', 'label' => 'Birinci'],
            ['key' => 'b', 'label' => 'İkinci'],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->service->decide($approval, true, null, $this->studio->portalUser, 'uydurma');
    }

    /** Variantlı razılaşdırmanı seçimsiz təsdiqləmək olmaz. */
    public function test_approving_a_multi_variant_request_without_a_choice_fails(): void
    {
        $approval = $this->request([
            ['key' => 'a', 'label' => 'Birinci'],
            ['key' => 'b', 'label' => 'İkinci'],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->service->decide($approval, true, null, $this->studio->portalUser);
    }

    /** Rədd edilmiş variantlı sorğuda seçim yazılmamalıdır. */
    public function test_rejecting_clears_the_chosen_variant(): void
    {
        $approval = $this->request([
            ['key' => 'a', 'label' => 'Birinci'],
            ['key' => 'b', 'label' => 'İkinci'],
        ]);

        $this->service->decide($approval, false, 'Hər ikisi uyğun deyil', $this->studio->portalUser, 'a');

        $this->assertNull($approval->fresh()->chosen_variant);
    }

    public function test_the_waiting_counter_only_runs_while_the_request_is_pending(): void
    {
        $approval = $this->request();
        $approval->forceFill(['created_at' => now()->subDays(4)])->save();

        $this->assertSame(4, $approval->fresh()->daysWaiting());

        $this->service->decide($approval->fresh(), true, null, $this->studio->portalUser);

        $this->assertSame(0, $approval->fresh()->daysWaiting(), 'Qərar veriləndən sonra sayğac dayanmalıdır.');
    }

    public function test_an_overdue_pending_request_is_flagged(): void
    {
        $approval = $this->request();
        $approval->forceFill(['respond_by' => now()->subDay()])->save();

        $this->assertTrue($approval->fresh()->isOverdue());
    }
}
