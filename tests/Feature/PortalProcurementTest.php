<?php

namespace Tests\Feature;

use App\Models\ProcurementItem;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Roomix-dəki «Procurement list» (`/complectation/<id>`) səhifəsi.
 *
 * Komplektasiya siyahısında studiyanın daxili pozisiyaları da saxlanılır
 * (`visible_to_client = false`), ona görə buradakı ən vacib yoxlamalar sızma
 * yoxlamalarıdır: daxili sətir nə HTML-ə, nə CSV-yə, nə də foto marşrutuna
 * düşməməlidir. İkinci sərhəd — yad müştərinin layihəsi (eyni studiyanın
 * BAŞQA müştərisi ilə yoxlanılır).
 */
class PortalProcurementTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private ProcurementItem $visibleItem;

    private ProcurementItem $internalItem;

    private ProcurementItem $foreignItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('procurement');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            // 2 × 100 = 200, 10% endirim → 180.
            $this->visibleItem = $this->studio->project->procurementItems()->create([
                'name' => 'Yemək masası',
                'analog' => 'Oval masa',
                'category' => 'Mebel',
                'room' => 'Qonaq otağı',
                'unit' => 'ədəd',
                'qty' => 2,
                'price' => 100,
                'discount_percent' => 10,
                'availability' => 'Stokda',
                'delivery_date' => '2026-10-05',
                'comment' => 'Rəng dəqiqləşdirilir',
                'purchase_status' => 'planned',
                'visible_to_client' => true,
            ]);

            // Endirimsiz sətir: `discount_percent` null olduqda endirimli məbləğ
            // yekunun özünə bərabər olmalıdır.
            $this->studio->project->procurementItems()->create([
                'name' => 'Kreslo', 'qty' => 1, 'price' => 500,
                'purchase_status' => 'planned', 'visible_to_client' => true,
            ]);

            // Studiyanın daxili pozisiyası — müştəri bunu HEÇ YERDƏ görməməlidir.
            $this->internalItem = $this->studio->project->procurementItems()->create([
                'name' => 'Daxili variant', 'qty' => 1, 'price' => 999,
                'photo_path' => 'procurement/internal.jpg',
                'purchase_status' => 'planned', 'visible_to_client' => false,
            ]);

            // Yad müştərinin layihəsindəki sətir: scope səhvdirsə dərhal görünər.
            $this->foreignItem = ProcurementItem::create([
                'project_id' => $this->studio->otherProject->id,
                'name' => 'Yad mebel', 'qty' => 1, 'price' => 777,
                'photo_path' => 'procurement/foreign.jpg',
                'purchase_status' => 'planned', 'visible_to_client' => true,
            ]);
        });
    }

    public function test_the_procurement_page_lists_the_items_shared_with_the_client(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement', $this->studio->project));

        $response->assertOk();
        $response->assertSee('Yemək masası');
        $response->assertSee('Oval masa');
        $response->assertSee('Qonaq otağı');
        $response->assertSee('Stokda');
        $response->assertSee('Kreslo');
    }

    /** ƏN VACİB: yad müştərinin komplektasiyası ümumiyyətlə açılmır. */
    public function test_a_customer_cannot_open_the_procurement_of_another_clients_project(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement', $this->studio->otherProject))
            ->assertNotFound();
    }

    public function test_a_customer_cannot_export_the_procurement_of_another_clients_project(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.export', $this->studio->otherProject))
            ->assertNotFound();
    }

    /** ƏN VACİB: yad layihənin foto marşrutu da bağlıdır. */
    public function test_a_customer_cannot_open_the_photo_of_another_clients_project(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.photo', [$this->studio->otherProject->id, $this->foreignItem->id]))
            ->assertNotFound();
    }

    /**
     * Öz layihəsinin daxili sətri də foto marşrutundan çıxmır — yoxsa
     * `visible_to_client` yalnız səhifədə işləyən «kosmetik» filtr olardı.
     */
    public function test_an_internal_items_photo_cannot_be_fetched(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.photo', [$this->studio->project->id, $this->internalItem->id]))
            ->assertNotFound();
    }

    public function test_an_internal_item_is_not_rendered_on_the_page(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement', $this->studio->project));

        $response->assertOk();
        $response->assertDontSee('Daxili variant');
        $response->assertDontSee('999.00');
        $response->assertDontSee('Yad mebel');
        // StudioWorld-un standart sətri bayraq vermir → default `false`.
        $response->assertDontSee($this->studio->procurementItem->name);
    }

    public function test_an_internal_item_is_not_exported_to_csv(): void
    {
        $csv = $this->csv();

        $this->assertStringNotContainsString('Daxili variant', $csv);
        $this->assertStringNotContainsString('999.00', $csv);
        $this->assertStringNotContainsString('Yad mebel', $csv);

        $this->assertStringContainsString('Kreslo', $csv);
    }

    public function test_the_discounted_total_is_calculated_correctly(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement', $this->studio->project));

        // Sətir səviyyəsi: 2 × 100 = 200, 10% → 180.
        $this->assertSame(200.0, (float) $this->visibleItem->total);
        $this->assertSame(180.0, $this->visibleItem->totalWithDiscount());

        // Yekunlar: 200 + 500 = 700 → endirimlə 180 + 500 = 680.
        // Daxili 999 heç birinə sayılmır.
        $this->assertSame(700.0, $response->viewData('totalBeforeDiscount'));
        $this->assertSame(680.0, $response->viewData('totalWithDiscount'));

        $response->assertSee('700.00');
        $response->assertSee('680.00');
    }

    /** Faiz boşdursa endirimli məbləğ yekunun özüdür (frontend şərtsiz istifadə edir). */
    public function test_an_item_without_a_discount_keeps_its_total(): void
    {
        $item = $this->studio->project->procurementItems()->where('name', 'Kreslo')->first();

        $this->assertNull($item->discount_percent);
        $this->assertSame(500.0, $item->totalWithDiscount());
    }

    public function test_the_csv_response_has_a_bom_and_the_expected_headers(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.export', $this->studio->project));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();

        // BOM olmasa Excel Azərbaycan hərflərini korlayır.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        // Boşluqlu başlıqları PHP dırnağa alır — bu, düzgün CSV-dir.
        $header = str_replace('"', '', rtrim(strtok(substr($csv, 3), "\n"), "\r"));
        $this->assertSame(
            'Ad;Analoq;Kateqoriya;Otaq;Ölçü vahidi;Say;Vahidin qiyməti;Cəmi;Endirim %;Endirimlə;'
            .'Mövcudluq;Razılaşdırma;Alınıb;Çatdırılma;Keçid;Çatdırılma/quraşdırma;Ehtiyat %;Şərh',
            $header
        );

        // Ayırıcı nöqtəli vergüldür; sətir və yekunlar faylın içindədir.
        $this->assertStringContainsString('"Yemək masası";"Oval masa";Mebel;"Qonaq otağı";ədəd;2.00;100.00;200.00;10.00;180.00', $csv);
        $this->assertStringContainsString('700.00', $csv);
        $this->assertStringContainsString('680.00', $csv);
    }

    public function test_a_guest_cannot_reach_the_procurement_list(): void
    {
        $this->get(route('portal.procurement', $this->studio->project))
            ->assertRedirect(route('portal.login'));
    }

    private function csv(): string
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.export', $this->studio->project));

        $response->assertOk();

        return $response->streamedContent();
    }
}
