<?php

namespace Tests\Feature\Fix;

use App\Enums\ApprovalStatus;
use App\Enums\ProjectStatus;
use App\Models\Approval;
use App\Models\DiaryEntry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA tapıntılarının regresiya testləri (müştəri portalı).
 *
 * Beş tapıntının hamısı eyni kökə söykənir: portal layihəni harada həll edir
 * və faylı brauzerə HANSI başlıqlarla verir. Ona görə testlər də həmin iki
 * sərhədi ölçür — sorğunun scope-u (kim nəyi görür/yazır) və media cavabı.
 */
class PortalFixTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->studio = StudioWorld::make('fixportal');
    }

    /** @param  callable():mixed  $callback */
    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    private function setProjectStatus(ProjectStatus $status): void
    {
        $this->inTenant(fn () => $this->studio->project->update(['status' => $status->value]));

        $this->studio->project->refresh();
    }

    /**
     * Studiyanın müştərini arxivləməsi (soft delete).
     *
     * `Client::booted()` yarımçıq layihəsi olan müştərini silməyə qoymur, ona
     * görə real axın da belədir: əvvəlcə layihələr arxivlənir, sonra müştəri.
     * Portal hesabı isə soft-delete olunmur — məhz bu boşluq tapıntının kökü idi.
     */
    private function archiveClient(): void
    {
        $this->inTenant(function (): void {
            $this->studio->client->projects()->update(['status' => ProjectStatus::Archived->value]);
            $this->studio->client->delete();
        });
    }

    // -----------------------------------------------------------------
    // 1. [CİDDİ] Arxivlənmiş müştəridə qlobal çat polleri
    // -----------------------------------------------------------------

    /**
     * Müştəri soft-delete olunanda `$viewer->client` null olur. `unread()`
     * trait-dən keçmədiyi üçün «Attempt to read property on null» atırdı —
     * özü də HƏR portal səhifəsində fonda pollinq edən endpointdə, yəni
     * fasiləsiz 500 və log zibili. Gözlənilən: səliqəli 403.
     */
    public function test_the_global_unread_poll_answers_403_for_an_archived_client(): void
    {
        $this->archiveClient();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->getJson(route('portal.chat.unread'))
            ->assertForbidden();
    }

    /** Normal halda endpoint işləməyə davam edir. */
    public function test_the_global_unread_poll_still_answers_for_an_active_client(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->getJson(route('portal.chat.unread'))
            ->assertOk()
            ->assertJsonStructure(['count']);
    }

    /** Arxivlənmiş müştəri layihə səhifəsində də 500 deyil, 403 görür. */
    public function test_an_archived_client_gets_403_on_a_project_page_too(): void
    {
        $this->archiveClient();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->studio->project->id))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // 2. [ORTA] Yanlış variant açarı
    // -----------------------------------------------------------------

    private function variantApproval(): Approval
    {
        return $this->inTenant(fn () => Approval::create([
            'approvable_type' => 'budget_line',
            'approvable_id' => $this->studio->budgetLine->id,
            'project_id' => $this->studio->project->id,
            'requested_by_user_id' => $this->studio->user('project_manager')->id,
            'client_user_id' => $this->studio->portalUser->id,
            'status' => ApprovalStatus::Pending->value,
            'variants' => [
                ['key' => 'a', 'label' => 'Variant A'],
                ['key' => 'b', 'label' => 'Variant B'],
            ],
        ]));
    }

    /** Siyahıda olmayan açar servisə ÇATMIR — 422, 500 deyil. */
    public function test_an_unknown_variant_key_is_a_validation_error_not_a_crash(): void
    {
        $approval = $this->variantApproval();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->postJson(route('portal.approvals.decide', $approval), [
                'decision' => 'approve',
                'variant' => 'uydurma',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');

        $this->assertSame(
            ApprovalStatus::Pending,
            $approval->fresh()->status,
            'Yanlış variantda status dəyişməməlidir.',
        );
    }

    /** Adi form göndərişində isə Laravel üslubu: geri yönləndirmə + xəta. */
    public function test_an_unknown_variant_key_sends_the_form_back_with_an_error(): void
    {
        $approval = $this->variantApproval();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->from(route('portal.approvals', $this->studio->project->id))
            ->post(route('portal.approvals.decide', $approval), [
                'decision' => 'approve',
                'variant' => 'uydurma',
            ])
            ->assertSessionHasErrors('variant');

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    /** Variantlı razılaşdırmanı seçimsiz təsdiqləmək də 422-dir (500 yox). */
    public function test_approving_a_variant_request_without_a_choice_is_a_validation_error(): void
    {
        $approval = $this->variantApproval();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->postJson(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    /** Düzgün açar əvvəlki kimi işləyir — düzəliş normal axını bağlamır. */
    public function test_a_valid_variant_key_is_still_accepted(): void
    {
        $approval = $this->variantApproval();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), [
                'decision' => 'approve',
                'variant' => 'b',
            ])
            ->assertRedirect();

        $approval->refresh();

        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertSame('b', $approval->chosen_variant);
    }

    // -----------------------------------------------------------------
    // 3. [ORTA] Arxivlənmiş layihə: oxu açıq, yazma bağlı
    // -----------------------------------------------------------------

    public function test_an_archived_project_can_still_be_read(): void
    {
        $this->setProjectStatus(ProjectStatus::Archived);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->studio->project->id))
            ->assertOk();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate.export', $this->studio->project->id))
            ->assertOk();
    }

    public function test_an_archived_project_does_not_accept_a_new_chat_message(): void
    {
        $this->setProjectStatus(ProjectStatus::Archived);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->postJson(route('portal.chat.send', $this->studio->project->id), [
                'body' => 'Arxivə yazmaq olmaz',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('chat_messages', ['body' => 'Arxivə yazmaq olmaz']);
    }

    public function test_an_archived_project_does_not_accept_an_approval_decision(): void
    {
        $this->setProjectStatus(ProjectStatus::Archived);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $this->studio->approval), ['decision' => 'approve'])
            ->assertForbidden();

        $this->assertSame(
            ApprovalStatus::Pending,
            $this->studio->approval->fresh()->status,
            'Arxivlənmiş layihədə qərar yazılmamalıdır.',
        );
    }

    /** Aktiv layihədə yazma qaydası dəyişmir. */
    public function test_an_active_project_still_accepts_a_chat_message(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->postJson(route('portal.chat.send', $this->studio->project->id), [
                'body' => 'Aktiv layihəyə mesaj',
            ])
            ->assertOk();

        $this->assertDatabaseHas('chat_messages', ['body' => 'Aktiv layihəyə mesaj']);
    }

    // -----------------------------------------------------------------
    // 4. [KİÇİK] Qaralama layihə portalda görünmür
    // -----------------------------------------------------------------

    public function test_a_draft_project_is_hidden_from_the_portal(): void
    {
        $this->setProjectStatus(ProjectStatus::Draft);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->studio->project->id))
            ->assertNotFound();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat', $this->studio->project->id))
            ->assertNotFound();
    }

    public function test_a_draft_project_is_not_listed_on_the_portal_home(): void
    {
        // Siyahı yalnız birdən çox görünən layihə olanda render olunur (bir
        // dənədə portal birbaşa ona yönləndirir), ona görə iki aktiv layihə
        // lazımdır — üçüncüsü isə qaralamadır və siyahıya düşməməlidir.
        $visible = $this->inTenant(fn () => collect(['Aktiv bir', 'Aktiv iki'])->map(
            fn (string $name) => $this->studio->client->projects()->create([
                'name' => $name,
                'type' => 'apartment',
                'status' => ProjectStatus::Active->value,
                'manager_user_id' => $this->studio->user('project_manager')->id,
            ]),
        ));

        $this->setProjectStatus(ProjectStatus::Draft);

        $response = $this->actingAs($this->studio->portalUser, 'customer')->get(route('portal.home'));

        $response->assertOk();
        $response->assertDontSee($this->studio->project->name);

        foreach ($visible as $project) {
            $response->assertSee($project->name);
        }
    }

    // -----------------------------------------------------------------
    // 5. [ORTA] Media marşrutlarında `nosniff` + tip ağ siyahısı
    // -----------------------------------------------------------------

    private function diaryEntryWithPhoto(string $path, string $contents): DiaryEntry
    {
        Storage::disk('public')->put($path, $contents);

        return $this->inTenant(fn () => $this->studio->project->diaryEntries()->create([
            'author_user_id' => $this->studio->user('designer')->id,
            'body' => 'Foto qeydi',
            'photos' => [$path],
            'published_at' => now()->subDay(),
        ]));
    }

    public function test_the_diary_photo_is_served_with_nosniff(): void
    {
        // Kiçik, həqiqi PNG — tip ağ siyahısından keçməlidir.
        $entry = $this->diaryEntryWithPhoto(
            'diary-photos/fix.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        );

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project->id, $entry->id, 0]));

        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * Diskə şəkil adı ilə HTML düşsə, portal onu ÖZ origin-ində sənəd kimi
     * açmamalıdır — yoxsa içindəki skript müştərinin sessiyası ilə işləyər.
     */
    public function test_an_html_file_disguised_as_a_diary_photo_is_not_served_inline(): void
    {
        $entry = $this->diaryEntryWithPhoto(
            'diary-photos/fix.jpg',
            '<html><body><script>alert(document.cookie)</script></body></html>',
        );

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project->id, $entry->id, 0]));

        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_procurement_photo_is_served_with_nosniff(): void
    {
        Storage::disk('public')->put(
            'procurement/fix.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        );

        $item = $this->inTenant(fn () => $this->studio->project->procurementItems()->create([
            'name' => 'Fotolu pozisiya', 'qty' => 1, 'price' => 10,
            'purchase_status' => 'planned', 'visible_to_client' => true,
            'photo_path' => 'procurement/fix.png',
        ]));

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.photo', [$this->studio->project->id, $item->id]));

        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    public function test_an_svg_procurement_photo_is_not_served_inline(): void
    {
        // SVG ağ siyahıda QƏSDƏN yoxdur: içində <script> ola bilər.
        Storage::disk('public')->put(
            'procurement/fix.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $item = $this->inTenant(fn () => $this->studio->project->procurementItems()->create([
            'name' => 'SVG pozisiya', 'qty' => 1, 'price' => 10,
            'purchase_status' => 'planned', 'visible_to_client' => true,
            'photo_path' => 'procurement/fix.svg',
        ]));

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.photo', [$this->studio->project->id, $item->id]));

        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }
}
