<?php

namespace Tests\Feature;

use App\Models\BudgetLine;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Roomix-dəki «Work estimate» (`/estimate/<id>`) səhifəsi.
 *
 * Smeta studiyanın daxili rəqəmlərini də saxlayır (`visible_to_client = false`),
 * ona görə buradakı ən vacib yoxlama məhz sızma yoxlamasıdır: daxili sətir nə
 * HTML-ə, nə də CSV ixracına düşməməlidir. İkinci sərhəd — yad müştərinin
 * layihəsi (eyni studiyanın başqa müştərisi ilə yoxlanılır).
 */
class PortalEstimateTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private BudgetLine $internalLine;

    private BudgetLine $secondLine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('estimate');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            // Studiyanın daxili sətri — müştəri bunu HEÇ YERDƏ görməməlidir.
            $this->internalLine = $this->studio->project->budgetLines()->create([
                'work_type' => 'Daxili marja', 'unit' => 'ədəd', 'qty' => 1,
                'work_price' => 999, 'material_price' => 0, 'position' => 2,
                'visible_to_client' => false,
            ]);

            $this->secondLine = $this->studio->project->budgetLines()->create([
                'work_type' => 'Tavan', 'room' => 'Qonaq otağı', 'unit' => 'm2', 'qty' => 5,
                'work_price' => 30, 'material_price' => 10, 'position' => 3,
                'visible_to_client' => true,
            ]);

            // Yad müştərinin layihəsindəki sətir: scope səhvdirsə dərhal görünər.
            $this->studio->otherProject->budgetLines()->create([
                'work_type' => 'Yad iş', 'unit' => 'm2', 'qty' => 1,
                'work_price' => 100, 'material_price' => 0, 'position' => 1,
                'visible_to_client' => true,
            ]);
        });
    }

    public function test_the_estimate_page_lists_the_lines_shared_with_the_client(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate', $this->studio->project));

        $response->assertOk();
        $response->assertSee($this->studio->budgetLine->work_type);
        $response->assertSee('Tavan');
        $response->assertSee('Qonaq otağı');
    }

    /** ƏN VACİB: daxili sətir səhifədə görünmür. */
    public function test_an_internal_line_is_not_rendered_on_the_page(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate', $this->studio->project));

        $response->assertOk();
        $response->assertDontSee('Daxili marja');
        $response->assertDontSee('999.00');
        $response->assertDontSee('Yad iş');
    }

    /** ƏN VACİB: daxili sətir CSV-yə də düşmür. */
    public function test_an_internal_line_is_not_exported_to_csv(): void
    {
        $csv = $this->csv();

        $this->assertStringNotContainsString('Daxili marja', $csv);
        $this->assertStringNotContainsString('999.00', $csv);
        $this->assertStringNotContainsString('Yad iş', $csv);

        $this->assertStringContainsString('Tavan', $csv);
        $this->assertStringContainsString($this->studio->budgetLine->work_type, $csv);
    }

    public function test_the_estimate_total_only_counts_visible_lines(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate', $this->studio->project));

        // 10 × (50 + 20) = 700, 5 × (30 + 10) = 200 → 900. Daxili 999 sayılmır.
        $this->assertSame(900.0, $response->viewData('total'));
        $response->assertSee('900.00');
    }

    public function test_the_csv_response_has_a_bom_and_the_expected_headers(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate.export', $this->studio->project));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();

        // BOM olmasa Excel Azərbaycan hərflərini korlayır.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        // Boşluqlu başlıqları PHP dırnağa alır — bu, düzgün CSV-dir və Excel
        // onu problemsiz açır; ona görə gözlənti də dırnaqlı yazılıb.
        $header = str_replace('"', '', rtrim(strtok(substr($csv, 3), "\n"), "\r"));
        $this->assertSame(
            'İş növü;Otaq;Ölçü vahidi;Həcm;İşin qiyməti;Materialın qiyməti;Cəmi;Status;Razılaşdırma',
            $header
        );

        // Ayırıcı nöqtəli vergüldür və yekun sətri faylın sonundadır.
        $this->assertStringContainsString('Tavan;"Qonaq otağı";m2;5.00;30.00;10.00;200.00', $csv);
        $this->assertStringContainsString('900.00', $csv);
    }

    public function test_a_customer_cannot_open_the_estimate_of_another_clients_project(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate', $this->studio->otherProject))
            ->assertNotFound();
    }

    public function test_a_customer_cannot_export_the_estimate_of_another_clients_project(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate.export', $this->studio->otherProject))
            ->assertNotFound();
    }

    public function test_a_guest_cannot_reach_the_estimate(): void
    {
        $this->get(route('portal.estimate', $this->studio->project))
            ->assertRedirect(route('portal.login'));
    }

    private function csv(): string
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate.export', $this->studio->project));

        $response->assertOk();

        return $response->streamedContent();
    }
}
