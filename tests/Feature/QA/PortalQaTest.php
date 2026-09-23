<?php

namespace Tests\Feature\QA;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentType;
use App\Enums\FileVisibility;
use App\Enums\ProjectStatus;
use App\Models\Approval;
use App\Models\ChatMessage;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Notifications\AutomationAlert;
use App\Notifications\PortalLoginLink;
use App\Services\Approvals\ApprovalService;
use App\Services\Portal\InvitationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * PRODA çıxış öncəsi QA — MÜŞTƏRİ PORTALI (brif istisna).
 *
 * Diqqət mərkəzi: parolsuz giriş, görünürlük filtri, IDOR, çat yükləmələri,
 * razılaşdırma, pul hesablamaları, bildirişlər və deaktiv müştəri.
 *
 * Hər `QA TAPINTI` şərhi həmin testin sənədləşdirdiyi problemdir.
 * «— DÜZƏLDİLDİ» qeydi olan tapıntılar bağlanıb: həmin testlər artıq YENİ
 * (doğru) davranışı yoxlayır — həm icazə verilən tərəfi (oxu işləyir), həm
 * rədd edilən tərəfi (yazma 403 / 422). Qeydi olmayan tapıntılar hələ AÇIQDIR
 * və test faktiki davranışı fiksə edir (yaşıl qalsın deyə).
 */
class PortalQaTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    /** Müştərinin heç bir məzmunu olmayan layihəsi — boş vəziyyət testləri. */
    private Project $emptyProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('portalqa');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $this->emptyProject = Project::create([
                'client_id' => $this->studio->client->id,
                'name' => 'Boş layihə',
                'type' => 'apartment',
                'status' => 'active',
                'manager_user_id' => $this->studio->user('project_manager')->id,
            ]);
        });
    }

    /** Tenant kontekstində qeyd yaratmaq üçün qısa yol. */
    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    /**
     * Müştərini arxivləyir (soft delete).
     *
     * — DÜZƏLDİLDİ: `Client::booted()`-a yeni `deleting` qapısı əlavə olunub —
     * tamamlanmamış (`draft`/`active`/`on_hold`) layihəsi olan müştəri
     * SİLİNMİR, `RuntimeException` atılır. Ona görə arxiv ssenarilərində
     * əvvəlcə müştərinin BÜTÜN layihələri (`project` + `emptyProject`)
     * `archived` edilir, sonra müştəri silinir.
     */
    private function archiveClient(): void
    {
        $this->inTenant(function (): void {
            $this->studio->client->projects()->update(['status' => ProjectStatus::Archived->value]);
            $this->studio->client->delete();
        });
    }

    // ---------------------------------------------------------------------
    // 1. PAROLSUZ GİRİŞ (magic link)
    // ---------------------------------------------------------------------

    private function magicLinkFor(ClientUser $user): string
    {
        Notification::fake();

        app(InvitationService::class)->sendLoginLink($user);

        $link = null;

        Notification::assertSentTo($user, PortalLoginLink::class, function ($notification) use (&$link) {
            $link = $notification->link;

            return true;
        });

        return $link;
    }

    public function test_magic_link_logs_the_customer_in(): void
    {
        $link = $this->magicLinkFor($this->studio->portalUser);

        $this->get($link)->assertRedirect(route('portal.home'));

        $this->assertAuthenticatedAs($this->studio->portalUser, 'customer');
        $this->assertNotNull($this->studio->portalUser->fresh()->last_login_at);
    }

    public function test_magic_link_is_single_use(): void
    {
        $link = $this->magicLinkFor($this->studio->portalUser);

        $this->get($link)->assertRedirect(route('portal.home'));

        // Token istifadədən sonra silinir — eyni link ikinci dəfə işləməməlidir.
        $this->post(route('portal.logout'));

        $this->get($link)->assertForbidden();
        $this->assertGuest('customer');
    }

    public function test_expired_magic_link_is_rejected(): void
    {
        $link = $this->magicLinkFor($this->studio->portalUser);

        $this->travel(31)->minutes();

        $this->get($link)->assertForbidden();
        $this->assertGuest('customer');
    }

    public function test_magic_link_with_a_tampered_token_is_rejected(): void
    {
        $link = $this->magicLinkFor($this->studio->portalUser);

        // İmza dəyişmədən token dəyişdirilə bilməz; imzasız cəhd də 403-dür.
        $this->get(route('portal.magic-login', [$this->studio->portalUser->id, 't' => Str::random(48)]))
            ->assertForbidden();

        $this->assertGuest('customer');
        $this->assertNotEmpty($link);
    }

    public function test_one_customers_magic_token_cannot_open_another_customers_account(): void
    {
        $link = $this->magicLinkFor($this->studio->portalUser);

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        // Eyni imzalı sorğunun yalnız id-sini dəyişmək imzanı pozur → 403.
        $this->get(route('portal.magic-login', [
            $this->studio->secondPortalUser->id,
            't' => $query['t'],
            'expires' => $query['expires'],
            'signature' => $query['signature'],
        ]))->assertForbidden();

        $this->assertGuest('customer');
    }

    public function test_magic_login_is_rate_limited_against_brute_force(): void
    {
        RateLimiter::clear('');

        $statuses = [];

        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->get(route('portal.magic-login', [
                $this->studio->portalUser->id, 't' => Str::random(48),
            ]))->getStatusCode();
        }

        // `throttle:auth` → dəqiqədə 5 cəhd/IP; 6-cı cəhd 429 olmalıdır.
        $this->assertSame(429, $statuses[6], 'Magic-login brute-force limiti işləmir.');
    }

    public function test_login_link_request_does_not_leak_which_emails_exist(): void
    {
        Notification::fake();

        $known = $this->from(route('portal.login'))
            ->post(route('portal.login-link'), ['email' => $this->studio->portalUser->email]);

        $unknown = $this->from(route('portal.login'))
            ->post(route('portal.login-link'), ['email' => 'yoxdur@portalqa.test']);

        $known->assertRedirect(route('portal.login'));
        $unknown->assertRedirect(route('portal.login'));
        $known->assertSessionHas('status');
        $unknown->assertSessionHas('status');
    }

    public function test_logout_kills_the_session(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.logout'))
            ->assertRedirect(route('portal.login'));

        $this->assertGuest('customer');

        $this->get(route('portal.home'))->assertRedirect(route('portal.login'));
    }

    public function test_guest_cannot_reach_any_portal_screen(): void
    {
        foreach ([
            route('portal.home'),
            route('portal.projects.show', $this->studio->project),
            route('portal.stages', $this->studio->project),
            route('portal.files', $this->studio->project),
            route('portal.diary', $this->studio->project),
            route('portal.documents', $this->studio->project),
            route('portal.payments', $this->studio->project),
            route('portal.estimate', $this->studio->project),
            route('portal.procurement', $this->studio->project),
            route('portal.approvals', $this->studio->project),
            route('portal.chat', $this->studio->project),
            route('portal.notifications'),
            route('portal.profile'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('portal.login'));
        }
    }

    // ---------------------------------------------------------------------
    // 2. GÖRÜNÜRLÜK FİLTRİ — siyahı VƏ birbaşa ID
    // ---------------------------------------------------------------------

    public function test_internal_file_is_hidden_in_the_list_and_on_direct_download(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files', $this->studio->project));

        $response->assertOk();
        $response->assertSee($this->studio->sharedFile->title);
        $response->assertDontSee($this->studio->internalFile->title);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files.download', [$this->studio->project, $this->studio->internalFile]))
            ->assertNotFound();
    }

    public function test_document_hidden_from_the_client_is_not_listed_and_not_downloadable(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents', $this->studio->project));

        $response->assertOk();
        $response->assertSee($this->studio->clientDocument->title);
        $response->assertDontSee($this->studio->internalDocument->title);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [$this->studio->project, $this->studio->internalDocument]))
            ->assertNotFound();

        // Qlobal sənəd hub-ı eyni filtri tətbiq etməlidir.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.all'))
            ->assertOk()
            ->assertDontSee($this->studio->internalDocument->title);
    }

    public function test_hidden_budget_line_is_absent_from_the_estimate_page_and_from_the_total(): void
    {
        $this->inTenant(function (): void {
            $this->studio->project->budgetLines()->create([
                'work_type' => 'Gizli marja', 'unit' => 'm2', 'qty' => 1,
                'work_price' => 9999, 'material_price' => 0, 'position' => 2,
                'visible_to_client' => false,
            ]);
        });

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate', $this->studio->project));

        $response->assertOk();
        $response->assertSee('Divar');
        $response->assertDontSee('Gizli marja');

        // Açıq sətir: 10 × (50 + 20) = 700. Gizli sətir cəmə düşməməlidir.
        $this->assertSame(700.0, (float) $response->viewData('total'));
    }

    public function test_hidden_budget_line_does_not_leak_through_the_csv_export(): void
    {
        $this->inTenant(function (): void {
            $this->studio->project->budgetLines()->create([
                'work_type' => 'Gizli marja', 'unit' => 'm2', 'qty' => 1,
                'work_price' => 9999, 'material_price' => 0, 'position' => 2,
                'visible_to_client' => false,
            ]);
        });

        $csv = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate.export', $this->studio->project))
            ->streamedContent();

        $this->assertStringNotContainsStringQuietly('Gizli marja', $csv, 'Gizli smeta sətri CSV ixracına düşür.');
        $this->assertStringNotContainsStringQuietly('9999', $csv, 'Gizli sətrin məbləği CSV-yə düşür.');
        $this->assertStringContainsStringQuietly('Divar', $csv, 'Açıq sətir CSV-də yoxdur.');
    }

    public function test_procurement_item_hidden_from_the_client_is_not_listed_exported_or_photo_served(): void
    {
        Storage::fake('public');

        $hidden = $this->inTenant(function () {
            // StudioWorld-dəki `procurementItem` `visible_to_client` verilmədən
            // yaradılır — miqrasiyada default `false`, yəni gizlidir.
            $item = $this->studio->project->procurementItems()->create([
                'name' => 'Gizli çilçıraq', 'qty' => 1, 'price' => 5000,
                'purchase_status' => 'planned', 'visible_to_client' => false,
                'photo_path' => 'procurement/hidden.jpg',
            ]);

            $this->studio->project->procurementItems()->create([
                'name' => 'Açıq stol', 'qty' => 2, 'price' => 100,
                'purchase_status' => 'planned', 'visible_to_client' => true,
            ]);

            return $item;
        });

        Storage::disk('public')->put('procurement/hidden.jpg', 'x');

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement', $this->studio->project));

        $response->assertOk();
        $response->assertSee('Açıq stol');
        $response->assertDontSee('Gizli çilçıraq');

        // Cəm yalnız açıq sətirlərdən: 2 × 100 = 200.
        $this->assertSame(200.0, (float) $response->viewData('totalBeforeDiscount'));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.photo', [$this->studio->project, $hidden]))
            ->assertNotFound();

        $csv = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.export', $this->studio->project))
            ->streamedContent();

        $this->assertStringNotContainsStringQuietly('Gizli çilçıraq', $csv, 'Gizli komplektasiya sətri CSV-yə düşür.');
    }

    public function test_unpublished_diary_entry_is_hidden_in_the_list_and_its_photo_is_not_served(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('diary/draft.jpg', 'x');
        Storage::disk('public')->put('diary/published.jpg', 'x');

        [$draft, $published] = $this->inTenant(fn () => [
            $this->studio->project->diaryEntries()->create([
                'author_user_id' => $this->studio->user('designer')->id,
                'body' => 'QARALAMA QEYD', 'photos' => ['diary/draft.jpg'], 'published_at' => null,
            ]),
            $this->studio->project->diaryEntries()->create([
                'author_user_id' => $this->studio->user('designer')->id,
                'body' => 'Dərc olunmuş qeyd', 'photos' => ['diary/published.jpg'], 'published_at' => now(),
            ]),
        ]);

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->project));

        $response->assertOk();
        $response->assertSee('Dərc olunmuş qeyd');
        $response->assertDontSee('QARALAMA QEYD');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project, $draft, 0]))
            ->assertNotFound();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project, $published, 0]))
            ->assertOk();

        // İndeks massivin hüdudundan kənara çıxa bilməz.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project, $published, 99]))
            ->assertNotFound();
    }

    /**
     * QA TAPINTI [CİDDİ] — çatda «daxili mesaj» anlayışı YOXDUR.
     *
     * `chat_messages` cədvəlində nə `internal`, nə `visible_to_client` sütunu
     * var (`database/migrations/2026_08_31_120000_create_chat_tables.php`), və
     * `ChatService::since()/search()` heç bir filtr tətbiq etmir. Yəni
     * heyətin layihə lentində yazdığı HƏR mesaj dərhal müştəriyə görünür —
     * daxili müzakirə üçün ayrıca kanal yoxdur. Test faktı fiksə edir.
     */
    public function test_chat_has_no_internal_message_concept_every_staff_message_reaches_the_client(): void
    {
        $this->assertFalse(
            Schema::hasColumn('chat_messages', 'internal'),
            'Əgər `internal` sütunu əlavə olunubsa, portal filtri də yenilənməlidir.'
        );

        $internalish = $this->inTenant(fn () => ChatMessage::create([
            'project_id' => $this->studio->project->id,
            'author_type' => 'user',
            'author_id' => $this->studio->user('project_manager')->id,
            'body' => 'DAXILI: müştəriyə demirik, marja 40%',
        ]));

        $poll = $this->actingAs($this->studio->portalUser, 'customer')
            ->getJson(route('portal.chat.poll', [$this->studio->project, 'after' => 0]));

        $poll->assertOk();

        $bodies = array_column($poll->json('messages'), 'body');

        // FAKTİKİ: daxili qeyd müştəriyə gedir.
        $this->assertContains($internalish->body, $bodies);
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: avtorizasiyalı media marşrutları artıq
     * `X-Content-Type-Options: nosniff` və ağ siyahılı `Content-Type` ilə gedir.
     *
     * `DiaryController::photo()` və `ProcurementController::photo()` indi ortaq
     * `imageResponse()` məntiqindən keçir: yalnız rastr şəkil tipləri
     * (`image/jpeg|png|gif|webp|avif|bmp`) `inline` verilir, siyahıdan kənar
     * hər şey `application/octet-stream` + `attachment` kimi gedir. SVG QƏSDƏN
     * ağ siyahıda deyil — içində `<script>` ola bilər və fayl tətbiqin ÖZ
     * origin-indən verilir.
     */
    public function test_authorized_media_routes_are_served_with_nosniff_and_a_type_allowlist(): void
    {
        Storage::fake('public');

        // Həqiqi JPEG imzası — tip məzmundan təyin olunur, uzantıdan yox.
        Storage::disk('public')->put('diary/p.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00");
        Storage::disk('public')->put('diary/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $entry = $this->inTenant(fn () => $this->studio->project->diaryEntries()->create([
            'author_user_id' => $this->studio->user('designer')->id,
            'body' => 'Qeyd', 'photos' => ['diary/p.jpg', 'diary/evil.svg'], 'published_at' => now(),
        ]));

        // 1) Ağ siyahıdakı şəkil — inline, amma nosniff ilə.
        $photo = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project, $entry, 0]));

        $photo->assertOk();
        $this->assertSame(
            'nosniff',
            $photo->headers->get('X-Content-Type-Options'),
            'Gündəlik fotosunda `nosniff` başlığı yoxdur.'
        );
        $this->assertStringContainsStringQuietly(
            'image/jpeg',
            (string) $photo->headers->get('Content-Type'),
            'Ağ siyahıdakı şəkil öz tipi ilə verilmir.'
        );
        $this->assertStringContainsStringQuietly(
            'inline',
            (string) $photo->headers->get('Content-Disposition'),
            'Ağ siyahıdakı şəkil inline verilmir.'
        );

        // 2) Siyahıdan kənar fayl (SVG) — brauzerdə icra olunmasın deyə
        //    neytral tip + `attachment`.
        $svg = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project, $entry, 1]));

        $svg->assertOk();
        $this->assertSame('nosniff', $svg->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsStringQuietly(
            'application/octet-stream',
            (string) $svg->headers->get('Content-Type'),
            'SVG hələ də öz tipi ilə verilir — portal origin-ində icra riski.'
        );
        $this->assertStringContainsStringQuietly(
            'attachment',
            (string) $svg->headers->get('Content-Disposition'),
            'SVG inline verilir — brauzer onu sənəd kimi açır.'
        );

        // 3) Eyni qayda komplektasiya fotosunda da işləyir.
        $item = $this->inTenant(fn () => $this->studio->project->procurementItems()->create([
            'name' => 'Açıq stol', 'qty' => 1, 'price' => 10,
            'purchase_status' => 'planned', 'visible_to_client' => true,
            'photo_path' => 'diary/p.jpg',
        ]));

        $itemPhoto = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.photo', [$this->studio->project, $item]));

        $itemPhoto->assertOk();
        $this->assertSame(
            'nosniff',
            $itemPhoto->headers->get('X-Content-Type-Options'),
            'Komplektasiya fotosunda `nosniff` başlığı yoxdur.'
        );
        $this->assertStringContainsStringQuietly(
            'image/jpeg',
            (string) $itemPhoto->headers->get('Content-Type'),
            'Komplektasiya fotosu ağ siyahılı tiplə verilmir.'
        );
    }

    // ---------------------------------------------------------------------
    // 3. IDOR — başqa müştərinin ID-ləri
    // ---------------------------------------------------------------------

    public function test_another_customer_cannot_open_any_tab_of_a_foreign_project(): void
    {
        $urls = [
            route('portal.projects.show', $this->studio->project),
            route('portal.stages', $this->studio->project),
            route('portal.files', $this->studio->project),
            route('portal.diary', $this->studio->project),
            route('portal.documents', $this->studio->project),
            route('portal.payments', $this->studio->project),
            route('portal.estimate', $this->studio->project),
            route('portal.estimate.export', $this->studio->project),
            route('portal.procurement', $this->studio->project),
            route('portal.procurement.export', $this->studio->project),
            route('portal.approvals', $this->studio->project),
            route('portal.chat', $this->studio->project),
            route('portal.chat.poll', $this->studio->project),
        ];

        foreach ($urls as $url) {
            $this->actingAs($this->studio->secondPortalUser, 'customer')
                ->get($url)
                ->assertNotFound($url);
        }
    }

    public function test_another_customer_cannot_download_foreign_files_documents_or_photos(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put($this->studio->sharedFile->file_path, 'x');
        Storage::disk('public')->put($this->studio->clientDocument->file_path, 'x');
        Storage::disk('public')->put('diary/p.jpg', 'x');
        Storage::disk('public')->put('chat/att.pdf', '%PDF-');

        [$entry, $message, $item] = $this->inTenant(fn () => [
            $this->studio->project->diaryEntries()->create([
                'author_user_id' => $this->studio->user('designer')->id,
                'body' => 'Qeyd', 'photos' => ['diary/p.jpg'], 'published_at' => now(),
            ]),
            ChatMessage::create([
                'project_id' => $this->studio->project->id,
                'author_type' => 'user',
                'author_id' => $this->studio->user('project_manager')->id,
                'body' => 'Fayl',
                'kind' => ChatMessage::KIND_FILE,
                'attachment_path' => 'chat/att.pdf',
                'attachment_name' => 'plan.pdf',
                'attachment_mime' => 'application/pdf',
                'attachment_size' => 6,
            ]),
            $this->studio->project->procurementItems()->create([
                'name' => 'Stol', 'qty' => 1, 'price' => 10,
                'purchase_status' => 'planned', 'visible_to_client' => true,
                'photo_path' => 'diary/p.jpg',
            ]),
        ]);

        $as = fn () => $this->actingAs($this->studio->secondPortalUser, 'customer');

        $as()->get(route('portal.files.download', [$this->studio->project, $this->studio->sharedFile]))->assertNotFound();
        $as()->get(route('portal.documents.download', [$this->studio->project, $this->studio->clientDocument]))->assertNotFound();
        $as()->get(route('portal.diary.photo', [$this->studio->project, $entry, 0]))->assertNotFound();
        $as()->get(route('portal.chat.attachment', [$this->studio->project, $message]))->assertNotFound();
        $as()->get(route('portal.procurement.photo', [$this->studio->project, $item]))->assertNotFound();
    }

    public function test_a_file_id_from_another_project_cannot_be_downloaded_through_my_own_project(): void
    {
        Storage::fake('public');

        $foreignFile = $this->inTenant(fn () => ProjectFile::create([
            'project_id' => $this->studio->otherProject->id,
            'category' => 'plan',
            'visibility' => FileVisibility::ClientShared->value,
            'title' => 'Yad plan',
            'file_path' => 'files/foreign.pdf',
        ]));

        Storage::disk('public')->put('files/foreign.pdf', 'x');

        // Öz layihəmin URL-i + yad faylın id-si → 404 (layihə əlaqəsindən həll olunur).
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files.download', [$this->studio->project, $foreignFile]))
            ->assertNotFound();
    }

    public function test_another_customer_cannot_post_into_a_foreign_projects_chat_or_decide_its_approval(): void
    {
        $this->actingAs($this->studio->secondPortalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), ['body' => 'Salam'])
            ->assertNotFound();

        $this->actingAs($this->studio->secondPortalUser, 'customer')
            ->post(route('portal.approvals.decide', $this->studio->approval), ['decision' => 'approve'])
            ->assertNotFound();

        $this->assertSame(
            ApprovalStatus::Pending,
            $this->studio->approval->fresh()->status,
            'Yad müştəri razılaşdırmanın statusunu dəyişə bildi.'
        );
    }

    public function test_another_customer_cannot_mark_a_foreign_notification_as_read(): void
    {
        $id = (string) Str::uuid();

        $this->studio->portalUser->notifications()->create([
            'id' => $id,
            'type' => AutomationAlert::class,
            'data' => ['title' => 'Mənim', 'project_id' => $this->studio->project->id],
            'read_at' => null,
        ]);

        $this->actingAs($this->studio->secondPortalUser, 'customer')
            ->post(route('portal.notifications.read-all'))
            ->assertRedirect();

        $this->assertNull(
            $this->studio->portalUser->notifications()->find($id)->read_at,
            '«Hamısını oxu» yad müştərinin bildirişini də oxunmuş etdi.'
        );
    }

    // ---------------------------------------------------------------------
    // 4. HƏR TAB YÜKLƏNİR — boş layihədə də
    // ---------------------------------------------------------------------

    public function test_every_tab_returns_200_on_a_completely_empty_project(): void
    {
        foreach ([
            'portal.projects.show', 'portal.stages', 'portal.files', 'portal.diary',
            'portal.documents', 'portal.payments', 'portal.estimate',
            'portal.procurement', 'portal.approvals', 'portal.chat',
        ] as $name) {
            $this->actingAs($this->studio->portalUser, 'customer')
                ->get(route($name, $this->emptyProject))
                ->assertOk($name);
        }

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.notifications'))->assertOk();
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.profile'))->assertOk();
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.approvals.all'))->assertOk();
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.all'))->assertOk();
    }

    public function test_csv_exports_do_not_break_on_an_empty_project(): void
    {
        $estimate = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate.export', $this->emptyProject));
        $estimate->assertOk();
        $this->assertNotEmpty($estimate->streamedContent());

        $procurement = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.export', $this->emptyProject));
        $procurement->assertOk();
        $this->assertNotEmpty($procurement->streamedContent());
    }

    public function test_the_hub_redirects_to_the_single_project_but_lists_several(): void
    {
        // secondClient-in bir layihəsi var → birbaşa ona yönləndirilir.
        $this->actingAs($this->studio->secondPortalUser, 'customer')
            ->get(route('portal.home'))
            ->assertRedirect(route('portal.projects.show', $this->studio->otherProject));

        // portalUser-in iki layihəsi var → siyahı.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.home'))
            ->assertOk()
            ->assertSee($this->studio->project->name)
            ->assertSee($this->emptyProject->name);
    }

    // ---------------------------------------------------------------------
    // 5. ÇAT — yükləmə validasiyası, XSS, redaktə/silmə
    // ---------------------------------------------------------------------

    public function test_chat_rejects_executable_and_disallowed_attachments(): void
    {
        Storage::fake('public');

        foreach (['shell.php', 'payload.svg', 'page.html', 'run.exe'] as $name) {
            $this->actingAs($this->studio->portalUser, 'customer')
                ->post(route('portal.chat.send', $this->studio->project), [
                    'attachment' => UploadedFile::fake()->create($name, 5),
                ], ['Accept' => 'application/json'])
                ->assertStatus(422, $name);
        }

        $this->assertSame(0, ChatMessage::where('project_id', $this->studio->project->id)
            ->whereNotNull('attachment_path')->count());
    }

    public function test_chat_rejects_a_php_payload_renamed_to_pdf(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->createWithContent('invoice.pdf', "<?php echo 'pwned';");

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), ['attachment' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_chat_rejects_an_oversized_attachment(): void
    {
        Storage::fake('public');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), [
                'attachment' => UploadedFile::fake()->create('big.pdf', 10241),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_chat_stores_an_attachment_under_a_random_path_not_the_client_filename(): void
    {
        Storage::fake('public');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), [
                'attachment' => UploadedFile::fake()->create('../../evil.pdf', 5, 'application/pdf'),
            ])
            ->assertOk();

        $message = ChatMessage::whereNotNull('attachment_path')->latest('id')->first();

        $this->assertNotNull($message);
        $this->assertStringStartsWith('chat/'.$this->studio->project->id.'/', $message->attachment_path);
        $this->assertStringNotContainsStringQuietly('..', $message->attachment_path, 'Fayl yolunda `..` var — traversal riski.');
        $this->assertStringNotContainsStringQuietly('/', $message->attachment_name, 'Saxlanan fayl adında qovluq ayırıcısı var.');
    }

    public function test_chat_message_body_is_escaped_on_the_page(): void
    {
        $xss = '<script>alert("xss")</script>';

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), ['body' => $xss])
            ->assertOk();

        $html = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat', [$this->studio->project, 'q' => 'xss']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsStringQuietly($xss, $html, 'Çat mesajı HTML kimi render olunur — saxlanan XSS.');
    }

    public function test_chat_json_payload_never_exposes_a_public_storage_url(): void
    {
        Storage::fake('public');

        $this->inTenant(fn () => ChatMessage::create([
            'project_id' => $this->studio->project->id,
            'author_type' => 'user',
            'author_id' => $this->studio->user('project_manager')->id,
            'kind' => ChatMessage::KIND_FILE,
            'attachment_path' => 'chat/secret.pdf',
            'attachment_name' => 'secret.pdf',
            'attachment_mime' => 'application/pdf',
            'attachment_size' => 10,
        ]));

        $json = $this->actingAs($this->studio->portalUser, 'customer')
            ->getJson(route('portal.chat.poll', [$this->studio->project, 'after' => 0]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsStringQuietly('/storage/chat/', $json, 'Çat əlavəsi birbaşa public URL ilə verilir.');
    }

    public function test_a_customer_has_no_route_to_edit_or_delete_a_chat_message(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'portal.'))
            ->filter(fn ($r) => array_intersect($r->methods(), ['DELETE', 'PUT']) !== [])
            ->map(fn ($r) => $r->getName())
            ->values()
            ->all();

        // Portalda silmə/əvəzləmə marşrutu yoxdur — mesaj redaktəsi mümkün deyil.
        $this->assertSame([], $routes, 'Portalda gözlənilməyən DELETE/PUT marşrutu var: '.implode(', ', $routes));
    }

    public function test_an_empty_chat_message_is_rejected(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->postJson(route('portal.chat.send', $this->studio->project), ['body' => ''])
            ->assertStatus(422);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->postJson(route('portal.chat.send', $this->studio->project), ['body' => str_repeat('a', 4001)])
            ->assertStatus(422);
    }

    public function test_chat_unread_counter_never_includes_another_clients_project(): void
    {
        $this->inTenant(fn () => ChatMessage::create([
            'project_id' => $this->studio->otherProject->id,
            'author_type' => 'user',
            'author_id' => $this->studio->user('owner')->id,
            'body' => 'Yad lent',
        ]));

        $mine = $this->actingAs($this->studio->portalUser, 'customer')
            ->getJson(route('portal.chat.unread'))->json('count');

        // Yalnız öz layihəsindəki 1 mesaj (StudioWorld `chatMessage`) sayılır.
        $this->assertSame(1, $mine);
    }

    // ---------------------------------------------------------------------
    // 6. RAZILAŞDIRMA
    // ---------------------------------------------------------------------

    public function test_an_approved_approval_cannot_be_decided_again(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $this->studio->approval), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame(ApprovalStatus::Approved, $this->studio->approval->fresh()->status);

        // İkinci qərar — rədd — qəbul edilməməlidir.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $this->studio->approval), [
                'decision' => 'reject', 'comment' => 'Fikrimi dəyişdim',
            ])
            ->assertForbidden();

        $this->assertSame(ApprovalStatus::Approved, $this->studio->approval->fresh()->status);
    }

    public function test_rejection_requires_a_comment(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $this->studio->approval), ['decision' => 'reject'])
            ->assertSessionHasErrors('comment');

        $this->assertSame(ApprovalStatus::Pending, $this->studio->approval->fresh()->status);
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: yanlış variant açarı artıq 500 deyil,
     * adi validasiya xətasıdır.
     *
     * `ApprovalController::decide()` indi icazəli açarları razılaşdırmanın ÖZ
     * `variants` siyahısından toplayır və `Rule::in(...)` tətbiq edir. Servisdəki
     * `InvalidArgumentException` son sədd kimi yerində qalır, amma portal ona
     * qədər getmir: XHR/JSON göndərişdə 422 + `variant` xətası, adi form
     * POST-unda isə geri yönləndirmə + `$errors`. Status hər iki halda DƏYİŞMİR.
     */
    public function test_an_unknown_variant_key_is_rejected_with_a_validation_error(): void
    {
        $approval = $this->inTenant(fn () => Approval::create([
            'approvable_type' => 'budget_line',
            'approvable_id' => $this->studio->budgetLine->id,
            'project_id' => $this->studio->project->id,
            'requested_by_user_id' => $this->studio->user('project_manager')->id,
            'client_user_id' => $this->studio->portalUser->id,
            'status' => ApprovalStatus::Pending->value,
            'variants' => [['key' => 'a', 'label' => 'Variant A'], ['key' => 'b', 'label' => 'Variant B']],
        ]));

        // XHR/JSON: 422 + sahə adı ilə xəta.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->postJson(route('portal.approvals.decide', $approval), [
                'decision' => 'approve', 'variant' => 'uydurma',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);

        // Adi form POST-u: geri yönləndirmə + sessiyada `$errors`.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->from(route('portal.approvals', $this->studio->project))
            ->post(route('portal.approvals.decide', $approval), [
                'decision' => 'approve', 'variant' => 'uydurma',
            ])
            ->assertRedirect(route('portal.approvals', $this->studio->project))
            ->assertSessionHasErrors('variant');

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);

        // İcazəli açar isə keçir — qayda yalnız YAD açarı bağlayır.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), [
                'decision' => 'approve', 'variant' => 'a',
            ])
            ->assertRedirect();

        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);
    }

    public function test_a_second_approval_for_the_same_row_gets_a_higher_version(): void
    {
        $second = $this->inTenant(fn () => app(ApprovalService::class)
            ->request($this->studio->budgetLine->fresh(), $this->studio->user('project_manager')));

        $this->assertGreaterThan(
            (int) $this->studio->approval->version,
            (int) $second->version,
            'Təkrar göndərişdə versiya artmır.'
        );

        // Köhnə pending göndəriş süpürülür — müştəri iki dəfə eyni şeyi görməməlidir.
        $this->assertNotSame(ApprovalStatus::Pending, $this->studio->approval->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // 7. ÖDƏNİŞLƏR VƏ SMETA — hesablama
    // ---------------------------------------------------------------------

    public function test_the_payments_tab_shows_every_payment_of_the_project_only(): void
    {
        $this->inTenant(function (): void {
            $this->studio->project->payments()->create([
                'title' => 'İkinci ödəniş', 'amount' => 1234.56,
                'status' => 'paid', 'due_date' => now()->subWeek(), 'paid_at' => now(),
            ]);

            $this->studio->otherProject->payments()->create([
                'title' => 'YAD ÖDƏNİŞ', 'amount' => 999, 'status' => 'pending', 'due_date' => now(),
            ]);
        });

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.payments', $this->studio->project));

        $response->assertOk();
        $response->assertSee('Avans');
        $response->assertSee('İkinci ödəniş');
        $response->assertDontSee('YAD ÖDƏNİŞ');

        $this->assertSame(2, $response->viewData('payments')->count());
    }

    public function test_estimate_rounding_is_stable_on_fractional_prices(): void
    {
        $this->inTenant(function (): void {
            $this->studio->project->budgetLines()->create([
                'work_type' => 'Kəsr', 'unit' => 'm2', 'qty' => 3,
                'work_price' => 0.1, 'material_price' => 0.2, 'position' => 3,
                'visible_to_client' => true,
            ]);
        });

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate', $this->studio->project));

        // 700 + 3 × 0.30 = 700.90 — float toplamada sürüşmə olmamalıdır.
        $this->assertSame(700.9, round((float) $response->viewData('total'), 2));
    }

    public function test_procurement_discount_math_matches_the_listed_rows(): void
    {
        $this->inTenant(function (): void {
            $this->studio->project->procurementItems()->create([
                'name' => 'Endirimli kreslo', 'qty' => 2, 'price' => 250,
                'discount_percent' => 10, 'purchase_status' => 'planned',
                'visible_to_client' => true,
            ]);
        });

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement', $this->studio->project));

        $response->assertOk();
        $this->assertSame(500.0, (float) $response->viewData('totalBeforeDiscount'));
        $this->assertSame(450.0, round((float) $response->viewData('totalWithDiscount'), 2));
    }

    public function test_csv_cells_are_protected_against_excel_formula_injection(): void
    {
        $this->inTenant(function (): void {
            $this->studio->project->budgetLines()->create([
                'work_type' => '=cmd|\' /C calc\'!A0', 'unit' => 'm2', 'qty' => 1,
                'work_price' => 1, 'material_price' => 0, 'position' => 9,
                'visible_to_client' => true,
            ]);
        });

        $csv = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate.export', $this->studio->project))
            ->streamedContent();

        $this->assertStringNotContainsStringQuietly('"=cmd', $csv, 'CSV xanası `=` ilə başlayır — düstur injection.');
    }

    // ---------------------------------------------------------------------
    // 8. BİLDİRİŞLƏR
    // ---------------------------------------------------------------------

    public function test_mark_all_read_only_touches_my_own_notifications(): void
    {
        $mine = (string) Str::uuid();
        $foreign = (string) Str::uuid();

        $this->studio->portalUser->notifications()->create([
            'id' => $mine, 'type' => AutomationAlert::class,
            'data' => ['title' => 'Mənim', 'project_id' => $this->studio->project->id], 'read_at' => null,
        ]);

        $this->studio->secondPortalUser->notifications()->create([
            'id' => $foreign, 'type' => AutomationAlert::class,
            'data' => ['title' => 'Yad', 'project_id' => $this->studio->otherProject->id], 'read_at' => null,
        ]);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.notifications.read-all'))
            ->assertRedirect();

        $this->assertNotNull($this->studio->portalUser->notifications()->find($mine)->read_at);
        $this->assertNull($this->studio->secondPortalUser->notifications()->find($foreign)->read_at);
    }

    public function test_notification_filters_split_the_feed_correctly(): void
    {
        $make = function (array $data): void {
            $this->studio->portalUser->notifications()->create([
                'id' => (string) Str::uuid(), 'type' => AutomationAlert::class,
                'data' => $data, 'read_at' => null,
            ]);
        };

        $make(['title' => 'TAPSIRIQ', 'project_id' => $this->studio->project->id, 'task_id' => $this->studio->task->id]);
        $make(['title' => 'LAYIHE', 'project_id' => $this->studio->project->id]);
        $make(['title' => 'XEBER']);

        $see = function (string $filter): string {
            return $this->actingAs($this->studio->portalUser, 'customer')
                ->get(route('portal.notifications', ['filter' => $filter]))
                ->assertOk()
                ->getContent();
        };

        $tasks = $see('tasks');
        $this->assertStringContainsStringQuietly('TAPSIRIQ', $tasks, 'tasks filtri tapşırıq bildirişini göstərmir.');
        $this->assertStringNotContainsStringQuietly('XEBER', $tasks, 'tasks filtrinə xəbər düşür.');

        $news = $see('news');
        $this->assertStringContainsStringQuietly('XEBER', $news, 'news filtri xəbəri göstərmir.');
        $this->assertStringNotContainsStringQuietly('TAPSIRIQ', $news, 'news filtrinə tapşırıq düşür.');

        $projects = $see('projects');
        $this->assertStringContainsStringQuietly('LAYIHE', $projects, 'projects filtri layihə bildirişini göstərmir.');
        $this->assertStringNotContainsStringQuietly('TAPSIRIQ', $projects, 'projects filtrinə tapşırıq düşür.');

        // Naməlum filtr «all»-a düşür, xəta vermir.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.notifications', ['filter' => "'; drop table--"]))
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // 9. DEAKTİV / SİLİNMİŞ MÜŞTƏRİ VƏ ARXİV LAYİHƏ
    // ---------------------------------------------------------------------

    public function test_a_revoked_portal_account_can_no_longer_authenticate(): void
    {
        // Real sessiya: əvvəlcə magic link ilə girir…
        $this->get($this->magicLinkFor($this->studio->portalUser))
            ->assertRedirect(route('portal.home'));

        $this->assertAuthenticatedAs($this->studio->portalUser, 'customer');

        // …sonra studiya portal girişini ləğv edir (soft delete).
        $this->studio->portalUser->delete();

        // Provider istifadəçini artıq tapa bilmir — növbəti sorğuda (yeni
        // proses, boş guard keşi) sessiya bərpa olunmur.
        $this->assertNull(
            $this->app['auth']->guard('customer')->getProvider()->retrieveById($this->studio->portalUser->id),
            'Ləğv edilmiş portal hesabı hələ də bazadan bərpa olunur.'
        );

        // Guard keşi sıfırlanır ki, sorğu real şəraiti təkrarlasın.
        $this->app['auth']->forgetGuards();

        $this->get(route('portal.home'))->assertRedirect(route('portal.login'));
        $this->assertGuest('customer');
    }

    public function test_an_archived_client_is_locked_out_of_every_portal_screen(): void
    {
        // — DÜZƏLDİLDİ (yalnız SETUP): müştərini birbaşa silmək artıq mümkün
        // deyil, `archiveClient()` əvvəlcə layihələri arxivləyir. Gözlənilən
        // davranışın özü dəyişməyib: hər ekran 403-dür.
        $this->archiveClient();

        foreach ([
            route('portal.home'),
            route('portal.profile'),
            route('portal.documents.all'),
            route('portal.approvals.all'),
        ] as $url) {
            $this->actingAs($this->studio->portalUser, 'customer')->get($url)->assertForbidden($url);
        }
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: `ChatController::unread()` indi
     * `ResolvesClientProjects` traitindən keçir.
     *
     * Əvvəl `$viewer->client->projects()` birbaşa çağırılırdı: müştəri
     * arxivləndikdə `client` `null` olurdu və hər portal səhifəsində arxa
     * planda işləyən bu poller fasiləsiz 500 verirdi. İndi marşrut
     * `clientProjects()`-dən keçir, trait-in `abort_if`-i işə düşür və
     * cavab digər ekranlarla EYNİ olur — 403.
     */
    public function test_archived_client_gets_403_from_the_global_chat_poller(): void
    {
        $this->archiveClient();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat.unread'))
            ->assertForbidden();
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: arxivlənmiş layihə artıq YALNIZ OXUNUR.
     *
     * `ResolvesClientProjects` indi oxu (`clientProject()`) və yazma
     * (`writableClientProject()`) yollarını ayırır. Arxiv müştəri üçün tarixçə
     * kimi açıq qalır — səhifələr və CSV ixracı 200 verir — amma arxiv layihəyə
     * yeni mesaj yazmaq və razılaşdırma qərarı vermək 403-dür.
     */
    public function test_an_archived_project_is_read_only_in_the_portal(): void
    {
        $this->inTenant(fn () => $this->studio->project->update(['status' => ProjectStatus::Archived->value]));

        // OXU açıqdır.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->studio->project))
            ->assertOk();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.estimate.export', $this->studio->project))
            ->assertOk();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat', $this->studio->project))
            ->assertOk();

        // YAZMA bağlıdır — arxiv layihəyə yeni mesaj düşmür.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->postJson(route('portal.chat.send', $this->studio->project), ['body' => 'Arxivdən salam'])
            ->assertForbidden();

        $this->assertSame(
            0,
            ChatMessage::where('project_id', $this->studio->project->id)
                ->where('body', 'Arxivdən salam')->count(),
            'Arxiv layihəyə mesaj yazıldı.'
        );

        // Razılaşdırma qərarı da yazma əməliyyatıdır.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $this->studio->approval), ['decision' => 'approve'])
            ->assertForbidden();

        $this->assertSame(ApprovalStatus::Pending, $this->studio->approval->fresh()->status);
    }

    /**
     * QA TAPINTI [KİÇİK] — DÜZƏLDİLDİ: `draft` statuslu layihə portalda
     * GÖRÜNMÜR.
     *
     * Filtr `clientProjects()` sorğusunun özündədir, ona görə həm siyahıdan
     * düşür, həm də birbaşa URL 404 verir. `emptyProject` qaralama olduqda
     * müştəridə yalnız bir görünən layihə qalır — `portal.home` birbaşa ona
     * yönləndirir (302).
     */
    public function test_a_draft_project_is_hidden_from_the_customer(): void
    {
        $this->inTenant(fn () => $this->emptyProject->update(['status' => ProjectStatus::Draft->value]));

        // Görünən yeganə layihə qalır → hub birbaşa ona yönləndirir.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.home'))
            ->assertRedirect(route('portal.projects.show', $this->studio->project));

        // Birbaşa URL də bağlıdır — qaralama layihə ümumiyyətlə həll olunmur.
        foreach ([
            'portal.projects.show', 'portal.stages', 'portal.files', 'portal.diary',
            'portal.documents', 'portal.payments', 'portal.estimate',
            'portal.procurement', 'portal.approvals', 'portal.chat',
        ] as $name) {
            $this->actingAs($this->studio->portalUser, 'customer')
                ->get(route($name, $this->emptyProject))
                ->assertNotFound($name);
        }

        // Aktiv layihə isə əvvəlki kimi açılır.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->studio->project))
            ->assertOk()
            ->assertSee($this->studio->project->name);
    }

    public function test_a_document_belonging_to_a_soft_deleted_project_is_not_reachable(): void
    {
        $this->inTenant(fn () => $this->studio->project->delete());

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents', $this->studio->project))
            ->assertNotFound();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [$this->studio->project, $this->studio->clientDocument]))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // 10. SESSİYA VƏ CSRF
    // ---------------------------------------------------------------------

    /**
     * CSRF yoxlaması testdə söndürülür (`ValidateCsrfToken::runningUnitTests()`),
     * ona görə qoruma marşrutun middleware siyahısı ilə yoxlanılır.
     */
    public function test_portal_write_routes_sit_behind_csrf_and_the_customer_guard(): void
    {
        $router = $this->app['router'];

        $webGroup = $router->getMiddlewareGroups()['web'] ?? [];

        // Laravel 13-də sinif `PreventRequestForgery` adlanır
        // (`ValidateCsrfToken` köhnə alias-dır) — hər ikisi qəbul olunur.
        $this->assertTrue(
            collect($webGroup)->contains(
                fn ($m) => in_array($m, [
                    PreventRequestForgery::class,
                    ValidateCsrfToken::class,
                ], true)
            ),
            '`web` qrupunda CSRF yoxlaması yoxdur.'
        );

        foreach ([
            'portal.chat.send', 'portal.approvals.decide',
            'portal.notifications.read-all', 'portal.logout',
        ] as $name) {
            $route = $router->getRoutes()->getByName($name);

            $this->assertNotNull($route, $name.' marşrutu yoxdur.');

            $middleware = $route->gatherMiddleware();

            $this->assertContains('web', $middleware, $name.' `web` qrupundan kənardadır (CSRF yoxdur).');

            $this->assertTrue(
                collect($middleware)->contains(fn ($m) => str_contains((string) $m, 'customer')),
                $name.' müştəri guard-ından kənardadır.'
            );
        }
    }

    public function test_portal_write_routes_are_rate_limited(): void
    {
        $router = $this->app['router'];

        foreach ([
            'portal.chat.send' => 'portal-write',
            'portal.approvals.decide' => 'portal-write',
            'portal.notifications.read-all' => 'portal-write',
            'portal.login-link' => 'auth',
            'portal.magic-login' => 'auth',
        ] as $name => $limiter) {
            $route = $router->getRoutes()->getByName($name);

            $this->assertContains(
                'throttle:'.$limiter,
                $route->gatherMiddleware(),
                $name.' marşrutunda `throttle:'.$limiter.'` yoxdur.'
            );
        }
    }

    public function test_a_document_id_from_a_foreign_project_is_not_reachable_through_my_project_url(): void
    {
        $foreign = $this->inTenant(fn () => Document::create([
            'project_id' => $this->studio->otherProject->id,
            'type' => DocumentType::Contract->value,
            'title' => 'Yad müqavilə',
            'file_path' => 'docs/foreign.pdf',
            'visible_to_client' => true,
        ]));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [$this->studio->project, $foreign]))
            ->assertNotFound();
    }
}
