<?php

namespace Tests\Feature\QA;

use App\Enums\ProjectStatus;
use App\Filament\Pages\ChatCenter;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\UserResource;
use App\Models\AutomationRule;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Concerns\BelongsToTenant;
use App\Models\DiaryEntry;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\TaskOverdue;
use App\Services\Automation\AutomationEngine;
use App\Services\Finance\ProfitabilityService;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA: STUDİYALAR ARASI TAM İZOLYASİYA.
 *
 * Müştərinin birbaşa tələbi: bir studiyanın tapşırıqlarını (və hər şeyini)
 * başqa studiya GÖRMƏMƏLİDİR. Bu fayl bunu HƏR model, HƏR Filament resursu,
 * HƏR portal fayl marşrutu və HƏR pul aqreqatı üzrə sübut edir.
 *
 * Metodika:
 *  - `StudioWorld::make('alpha')` və `::make('beta')` iki ayrı studiyadır.
 *  - HTML/JSON yoxlamaları üçün beta dünyasına UNİKAL «BETAMARKER» sətirləri
 *    əlavə olunur. StudioWorld-un öz adları («Planlaşdırma», «Avans»,
 *    «Müqavilə», «Eskiz») HƏR İKİ dünyada eynidir — onlarla axtarış saxta
 *    pozitiv verir, ona görə istifadə edilmir.
 *  - Heç bir iddia təxminə əsaslanmır; hamısı işləyən testdir.
 */
class TenantIsolationQaTest extends TestCase
{
    use RefreshDatabase;

    /** Yalnız beta dünyasında rast gəlinən unikal sətir. */
    private const MARK = 'BETAMARKER';

    private StudioWorld $alpha;

    private StudioWorld $beta;

    private bool $markersSeeded = false;

    /**
     * `tenant_id` sütunu olan, amma qəsdən `BelongsToTenant` İŞLƏTMƏYƏN
     * cədvəllər. Bunlar paylaşılan kataloqlardır: `tenant_id = null` platforma
     * sətri, studiyanın öz sətri onu kölgələyir (copy-on-write). İzolyasiya
     * `forTenant()` scope-u ilə gəlir və aşağıda ayrıca test olunur.
     *
     * @var array<string, string>
     */
    private const SHARED_CATALOG_TABLES = [
        'roles' => 'Role::scopeForTenant + RoleResource::getEloquentQuery',
        'automation_rules' => 'AutomationRule::scopeForTenant + AutomationRuleResource::getEloquentQuery',
        'automation_runs' => 'Eloquent modeli yoxdur; tenant_id dedup açarının bir hissəsidir',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = StudioWorld::make('alpha');
        $this->beta = StudioWorld::make('beta');
    }

    // ----------------------------------------------------------------------
    // 1. MODEL SƏVİYYƏSİ — HƏR MODEL ÜZRƏ SİYAHI VƏ SÜBUT
    // ----------------------------------------------------------------------

    /**
     * Sxem ilə kod arasındakı boşluq: `tenant_id` sütunu olan, amma nə
     * `BelongsToTenant`, nə də `forTenant()` scope-u olmayan model BLOKER olardı.
     */
    public function test_every_table_with_a_tenant_id_column_is_scoped_somewhere(): void
    {
        $unscoped = [];
        $covered = 0;

        foreach ($this->allTables() as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            if (array_key_exists($table, self::SHARED_CATALOG_TABLES)) {
                continue;
            }

            $model = $this->modelForTable($table);

            if ($model === null) {
                $unscoped[] = "{$table} (Eloquent modeli tapılmadı)";

                continue;
            }

            if ($this->usesTenantTrait($model)) {
                $covered++;
            } else {
                $unscoped[] = "{$table} => {$model} (BelongsToTenant yoxdur)";
            }
        }

        $this->assertGreaterThanOrEqual(26, $covered, 'Sxem gözləniləndən az tenant-lı cədvəl verdi.');
        $this->assertSame([], $unscoped, 'tenant_id sütunu var, qlobal scope yoxdur: '.implode(', ', $unscoped));
    }

    /** Əksi: trait var, amma sütun yoxdursa scope sessizcə sıradan çıxar. */
    public function test_every_model_using_the_trait_really_has_the_column(): void
    {
        $broken = [];

        foreach ($this->tenantModels() as $model) {
            $table = (new $model)->getTable();

            if (! Schema::hasColumn($table, 'tenant_id')) {
                $broken[] = "{$model} => {$table}";
            }
        }

        $this->assertNotEmpty($this->tenantModels());
        $this->assertSame([], $broken, 'BelongsToTenant var, tenant_id sütunu yoxdur: '.implode(', ', $broken));
    }

    /**
     * ƏSAS SÜBUT — hər tenant-lı model üçün: beta-nın sətri alpha kontekstində
     * nə `::all()`, nə `::find(id)`, nə də `::count()` ilə GÖRÜNMÜR.
     */
    public function test_no_tenant_model_leaks_a_foreign_row_in_the_other_studios_context(): void
    {
        $this->seedBetaMarkers();

        $ctx = app(TenantContext::class);
        $leaks = [];
        $checkedModels = 0;

        foreach ($this->tenantModels() as $model) {
            $betaIds = $ctx->actingAs($this->beta->tenant->id, fn () => $model::query()->pluck('id')->all());

            if ($betaIds === []) {
                continue; // Bu model üçün beta dünyasında sətir yoxdur.
            }

            $checkedModels++;

            $ctx->actingAs($this->alpha->tenant->id, function () use ($model, $betaIds, &$leaks) {
                $overlap = array_intersect($model::query()->pluck('id')->all(), $betaIds);

                if ($overlap !== []) {
                    $leaks[] = "{$model}::all() beta sətirlərini göstərdi: ".implode(',', $overlap);
                }

                foreach ($betaIds as $id) {
                    if ($model::query()->find($id) !== null) {
                        $leaks[] = "{$model}::find({$id}) beta sətrini qaytardı";
                    }
                }

                if ($model::query()->whereIn('id', $betaIds)->count() > 0) {
                    $leaks[] = "{$model}::count() beta sətirlərini saydı";
                }
            });
        }

        // 19 = StudioWorld + marker seed-in beta dünyasında sətir yaratdığı
        // tenant-lı modellərin sayı. Qalanları (Brief, ChangeRequest,
        // Deliverable, PunchListIssue, ProjectDecision, SpecificationItem)
        // bu dünyalarda qurulmur.
        $this->assertGreaterThanOrEqual(19, $checkedModels, 'Çox az model yoxlandı — marker seed-i işləmir.');
        $this->assertSame([], $leaks, implode(PHP_EOL, $leaks));
    }

    /**
     * Soft-delete edilmiş sətirlər də sızmamalıdır (`withTrashed` yolu).
     *
     * SETUP QEYDİ — `Client::booted()`-a yeni `deleting` qapısı əlavə olunub:
     * tamamlanmamış (`draft`/`active`/`on_hold`) layihəsi olan müştəri silinmir.
     * `StudioWorld` müştərini `active` layihə ilə qurduğu üçün silmədən əvvəl
     * layihə arxivlənir. Testin MƏQSƏDİ dəyişmir — yoxlanılan yenə də
     * soft-delete edilmiş YAD sətrin alfa kontekstində görünməməsidir.
     */
    public function test_soft_deleted_foreign_rows_stay_invisible(): void
    {
        $ctx = app(TenantContext::class);

        $ctx->actingAs($this->beta->tenant->id, function () {
            $this->beta->project->update(['status' => ProjectStatus::Archived->value]);
            $this->beta->client->delete();
        });

        $this->assertSoftDeleted('clients', ['id' => $this->beta->client->id]);

        $ctx->actingAs($this->alpha->tenant->id, function () {
            $this->assertNull(Client::withTrashed()->find($this->beta->client->id));
            $this->assertSame(0, Client::withTrashed()->whereKey($this->beta->client->id)->count());
            $this->assertSame(0, Client::onlyTrashed()->whereKey($this->beta->client->id)->count());
        });
    }

    /** Paylaşılan kataloqlar: studiyanın öz `Role` sətri yad studiyaya görünmür. */
    public function test_shared_catalog_rows_are_scoped_by_for_tenant(): void
    {
        $betaRole = Role::create([
            'tenant_id' => $this->beta->tenant->id,
            'key' => 'beta_custom',
            'name' => self::MARK.' rol',
            'levels' => [],
            'is_system' => false,
            'active' => true,
        ]);

        $this->assertNotContains(
            $betaRole->id,
            Role::query()->forTenant($this->alpha->tenant->id)->pluck('id')->all(),
            'Beta-nın xüsusi rolu alpha-nın rol siyahısına düşdü.',
        );

        // Platformanın ümumi (tenant_id = null) sətri hər ikisinə görünür — dizayn budur.
        $shared = Role::create([
            'tenant_id' => null, 'key' => 'shared_key', 'name' => 'Ümumi',
            'levels' => [], 'is_system' => true, 'active' => true,
        ]);

        $this->assertContains($shared->id, Role::query()->forTenant($this->alpha->tenant->id)->pluck('id')->all());
    }

    /**
     * QA TAPINTI [ORTA] — `tenant_id` sütunu OLMAYAN uşaq modelləri
     * (`ChatMessage`, `Comment`, `ClientContactLog`, `Brief*`, `StageTemplate*`)
     * öz-özlüyündə scope-suzdur; izolyasiya yalnız valideyndən
     * (`Project` / `Client` / `Brief`) gəlir. Bu test həmin asılılığı AÇIQ
     * şəkildə sənədləşdirir: birbaşa `ChatMessage::query()` HƏR İKİ studiyanın
     * mesajını qaytarır.
     *
     * Praktikada hazırda sızma yoxdur — bütün giriş nöqtələri (ChatService,
     * Staff/Portal ChatController, ChatCenter) sorğunu `$project->chatMessages()`
     * ilə başladır (aşağıdakı HTTP testləri bunu sübut edir). Risk gələcəkdədir:
     * `ChatMessage::query()->…` yazan HƏR yeni kod parçası sızdıracaq.
     */
    public function test_untenanted_child_models_are_only_protected_through_their_parent(): void
    {
        $ctx = app(TenantContext::class);

        $seenFromAlpha = $ctx->actingAs(
            $this->alpha->tenant->id,
            fn () => ChatMessage::query()->pluck('id')->all(),
        );

        // Faktiki vəziyyət sənədləşdirilir (bu, iddia deyil, xəbərdarlıqdır).
        $this->assertContains(
            $this->beta->chatMessage->id,
            $seenFromAlpha,
            'ChatMessage artıq scope-lanıb — yuxarıdakı QA TAPINTI şərhini yeniləyin.',
        );

        // Dəstəklənən yeganə giriş yolu (valideyn münasibəti) isə təmizdir:
        $viaProject = $ctx->actingAs(
            $this->alpha->tenant->id,
            fn () => Project::query()->with('chatMessages')->get()
                ->flatMap->chatMessages->pluck('id')->all(),
        );

        $this->assertNotContains($this->beta->chatMessage->id, $viaProject);
    }

    // ----------------------------------------------------------------------
    // 2. YAZMA TƏRƏFİ
    // ----------------------------------------------------------------------

    /** Gizli təyinat: yeni sətir HƏMİŞƏ cari kontekstin tenant-ını alır. */
    public function test_create_always_stamps_the_active_tenant(): void
    {
        $task = app(TenantContext::class)->actingAs(
            $this->alpha->tenant->id,
            fn () => Task::create([
                'project_id' => $this->alpha->project->id,
                'stage_id' => $this->alpha->stage->id,
                'title' => 'Alpha tapşırığı',
                'status' => 'todo',
            ]),
        );

        $this->assertSame($this->alpha->tenant->id, $task->tenant_id);
    }

    /**
     * Alpha kodu beta-nın layihə ID-sini uydursa belə, sətir ALPHA-nın tenant-ı
     * ilə damğalanır — yəni beta onu heç vaxt görmür. Sətrin özü məntiqsiz
     * qalır, amma məxfilik pozulmur.
     */
    public function test_a_forged_foreign_project_id_still_lands_in_the_writers_tenant(): void
    {
        $ctx = app(TenantContext::class);

        $task = $ctx->actingAs($this->alpha->tenant->id, fn () => Task::create([
            'project_id' => $this->beta->project->id,
            'stage_id' => $this->beta->stage->id,
            'title' => 'Saxta tapşırıq',
            'status' => 'todo',
        ]));

        $this->assertSame($this->alpha->tenant->id, $task->tenant_id);

        $ctx->actingAs(
            $this->beta->tenant->id,
            fn () => $this->assertSame(0, Task::query()->where('title', 'Saxta tapşırıq')->count()),
        );
    }

    /** HTTP: alpha işçisi beta-nın layihəsinə çat mesajı yaza bilmir. */
    public function test_staff_cannot_write_chat_into_a_foreign_studio_project(): void
    {
        $response = $this->actingAs($this->alpha->user('owner'))
            ->postJson(route('staff.chat.send', ['project' => $this->beta->project->id]), [
                'body' => 'Yad studiyaya mesaj (QA)',
            ]);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseMissing('chat_messages', ['body' => 'Yad studiyaya mesaj (QA)']);
    }

    /** HTTP: portal müştərisi yad studiyanın layihəsinə heç nə yaza bilmir. */
    public function test_portal_customer_cannot_write_into_a_foreign_studio(): void
    {
        $response = $this->actingAs($this->alpha->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->beta->project->id), ['body' => 'QA yad mesaj']);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseMissing('chat_messages', ['body' => 'QA yad mesaj']);
    }

    // ----------------------------------------------------------------------
    // 3. FİLAMENT RESURSLARI — HƏR RESURS, SİYAHI + EDIT/VIEW
    // ----------------------------------------------------------------------

    /**
     * Panelin BÜTÜN qeydə alınmış resursları üzrə gəzir: alpha-nın sahibkarı
     * beta-nın qeyd ID-si ilə birbaşa URL açanda 403/404 almalıdır.
     */
    public function test_no_filament_resource_opens_a_foreign_studios_record(): void
    {
        $this->seedBetaMarkers();

        $ctx = app(TenantContext::class);
        $owner = $this->alpha->user('owner');
        $failures = [];
        $checked = 0;

        foreach ($this->panelResources() as $resource) {
            $model = $resource::getModel();

            $betaId = $ctx->actingAs($this->beta->tenant->id, fn () => $model::query()->value('id'));

            if ($betaId === null) {
                continue;
            }

            foreach (['edit', 'view'] as $page) {
                if (! $resource::hasPage($page)) {
                    continue;
                }

                $url = $resource::getUrl($page, ['record' => $betaId], panel: 'app');
                $status = $this->actingAs($owner)->get($url)->status();
                $checked++;

                if (! in_array($status, [403, 404], true)) {
                    $failures[] = "{$resource}::{$page} (record {$betaId}) => {$status}";
                }
            }
        }

        $this->assertGreaterThanOrEqual(10, $checked, "Yalnız {$checked} resurs səhifəsi yoxlandı — əhatə çox dardır.");
        $this->assertSame([], $failures, 'Yad studiyanın qeydi açıldı: '.implode(', ', $failures));
    }

    /** Siyahı səhifələrinin heç birində beta-nın unikal markeri görünməməlidir. */
    public function test_no_filament_list_page_prints_a_foreign_studios_record(): void
    {
        $this->seedBetaMarkers();

        $owner = $this->alpha->user('owner');
        $failures = [];
        $rendered = 0;

        foreach ($this->panelResources() as $resource) {
            if (! $resource::hasPage('index')) {
                continue;
            }

            $response = $this->actingAs($owner)->get($resource::getUrl('index', panel: 'app'));

            if ($response->status() !== 200) {
                continue;
            }

            $rendered++;

            foreach ($this->foreignNeedles() as $needle) {
                if (str_contains($response->getContent(), $needle)) {
                    $failures[] = "{$resource}::index → «{$needle}»";
                }
            }
        }

        $this->assertGreaterThanOrEqual(8, $rendered, 'Çox az siyahı səhifəsi render olundu.');
        $this->assertSame([], $failures, 'Siyahıda yad studiyanın məlumatı göründü: '.implode(', ', $failures));
    }

    /** Panel səhifələri: Çat mərkəzi, Diqqət, Təqvim, Rentabellik, Planlayıcı, Dashboard. */
    public function test_no_filament_page_prints_a_foreign_studios_data(): void
    {
        $this->seedBetaMarkers();

        $owner = $this->alpha->user('owner');
        $failures = [];
        $rendered = 0;

        foreach ($this->panelPages() as $page) {
            $response = $this->actingAs($owner)->get($page::getUrl(panel: 'app'));

            if ($response->status() !== 200) {
                continue;
            }

            $rendered++;

            foreach ($this->foreignNeedles() as $needle) {
                if (str_contains($response->getContent(), $needle)) {
                    $failures[] = "{$page} → «{$needle}»";
                }
            }
        }

        $this->assertGreaterThanOrEqual(3, $rendered, 'Çox az panel səhifəsi render olundu.');
        $this->assertSame([], $failures, 'Panel səhifəsi yad studiyanın məlumatını göstərdi: '.implode(', ', $failures));
    }

    /** Çat mərkəzi: yad layihə ID-si ilə birbaşa açılış bloklanır. */
    public function test_chat_center_refuses_a_foreign_project_id(): void
    {
        $url = ChatCenter::getUrl(panel: 'app').'?project='.$this->beta->project->id;

        $response = $this->actingAs($this->alpha->user('owner'))->get($url);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertStringNotContainsStringQuietly(
            $this->beta->chatMessage->body,
            $response->getContent(),
            'Çat mərkəzi yad studiyanın mesajını göstərdi.',
        );
    }

    /** Qlobal axtarış — `whereTenant` unudulmasının klassik yeri. */
    public function test_global_search_never_returns_a_foreign_studios_record(): void
    {
        $this->seedBetaMarkers();

        $ctx = app(TenantContext::class);
        $failures = [];
        $searched = 0;

        // `canGloballySearch()` avtorizasiyadan asılıdır — həm auth, həm də
        // tenant konteksti qurulmalıdır, əks halda test sessizcə boş işləyir.
        $this->actingAs($this->alpha->user('owner'));

        $ctx->actingAs($this->alpha->tenant->id, function () use (&$failures, &$searched) {
            foreach ($this->panelResources() as $resource) {
                if ($resource::getGloballySearchableAttributes() === []) {
                    continue;
                }

                $searched++;

                foreach ([self::MARK, 'Beta'] as $term) {
                    foreach ($resource::getGlobalSearchResults($term) as $result) {
                        $failures[] = "{$resource} → «{$result->title}»";
                    }
                }
            }
        });

        $this->assertGreaterThanOrEqual(5, $searched, 'Qlobal axtarışa açıq resurs tapılmadı.');
        $this->assertSame([], $failures, 'Qlobal axtarış yad studiyanın qeydini qaytardı: '.implode(', ', $failures));
    }

    // ----------------------------------------------------------------------
    // 4. PORTAL — XÜSUSİLƏ FAYL ENDİRMƏ MARŞRUTLARI
    // ----------------------------------------------------------------------

    /**
     * Fayl/foto/sənəd marşrutları scope-dan kənarda qalmağa ən meyilli
     * yerlərdir: hər biri həm «yad layihə + yad qeyd», həm də daha incə
     * «ÖZ layihəm + yad qeyd» kombinasiyası ilə yoxlanılır.
     */
    public function test_portal_file_routes_refuse_foreign_studio_records(): void
    {
        $extras = $this->betaExtras();
        $customer = $this->alpha->portalUser;
        $own = $this->alpha->project->id;
        $foreign = $this->beta->project->id;

        $urls = [
            // Yad layihə + yad qeyd
            route('portal.files.download', ['project' => $foreign, 'file' => $this->beta->sharedFile->id]),
            route('portal.files.download', ['project' => $foreign, 'file' => $this->beta->internalFile->id]),
            route('portal.documents.download', ['project' => $foreign, 'document' => $this->beta->clientDocument->id]),
            route('portal.diary.photo', ['project' => $foreign, 'entry' => $extras['diary']->id, 'index' => 0]),
            route('portal.procurement.photo', ['project' => $foreign, 'item' => $this->beta->procurementItem->id]),
            route('portal.chat.attachment', ['project' => $foreign, 'message' => $extras['chatFile']->id]),
            route('portal.estimate.export', ['project' => $foreign]),
            route('portal.procurement.export', ['project' => $foreign]),
            route('portal.diary', ['project' => $foreign]),
            // ÖZ layihəm + YAD qeyd (ID uyğunsuzluğu — scope-un ən incə yeri)
            route('portal.files.download', ['project' => $own, 'file' => $this->beta->sharedFile->id]),
            route('portal.documents.download', ['project' => $own, 'document' => $this->beta->clientDocument->id]),
            route('portal.diary.photo', ['project' => $own, 'entry' => $extras['diary']->id, 'index' => 0]),
            route('portal.procurement.photo', ['project' => $own, 'item' => $this->beta->procurementItem->id]),
            route('portal.chat.attachment', ['project' => $own, 'message' => $extras['chatFile']->id]),
        ];

        $failures = [];

        foreach ($urls as $url) {
            $status = $this->actingAs($customer, 'customer')->get($url)->status();

            if (! in_array($status, [403, 404], true)) {
                $failures[] = "{$url} => {$status}";
            }
        }

        $this->assertSame([], $failures, 'Portal yad studiyanın qeydini verdi: '.implode(', ', $failures));
    }

    /** Portalın qlobal (layihədən asılı olmayan) lentləri. */
    public function test_portal_global_lists_contain_nothing_from_the_other_studio(): void
    {
        $this->seedBetaMarkers();

        $customer = $this->alpha->portalUser;
        $failures = [];
        $rendered = 0;

        $pages = [
            route('portal.home'),
            route('portal.approvals.all'),
            route('portal.documents.all'),
            route('portal.notifications'),
            route('portal.profile'),
        ];

        foreach ($pages as $url) {
            $response = $this->actingAs($customer, 'customer')->get($url);

            if ($response->status() !== 200) {
                continue;
            }

            $rendered++;

            foreach ($this->foreignNeedles() as $needle) {
                if (str_contains($response->getContent(), $needle)) {
                    $failures[] = "{$url} → «{$needle}»";
                }
            }
        }

        $this->assertGreaterThanOrEqual(4, $rendered, 'Portal səhifələri render olunmadı.');
        $this->assertSame([], $failures, 'Portal lenti yad studiyanın məlumatını göstərdi: '.implode(', ', $failures));
    }

    /** Çat oxunmamış sayğacı başqa studiyanın layihələrini saymamalıdır. */
    public function test_unread_counters_do_not_count_the_other_studio(): void
    {
        $response = $this->actingAs($this->alpha->user('owner'))->getJson(route('staff.chat.unread'));
        $response->assertOk();

        $payload = $response->getContent();

        $this->assertStringNotContainsStringQuietly(
            '"'.$this->beta->project->id.'"',
            $payload,
            'İşçi çat sayğacında yad studiyanın layihə ID-si var.',
        );
    }

    // ----------------------------------------------------------------------
    // 5. İŞÇİ TƏRƏFİ — FAYL ENDİRMƏ VƏ TƏQVİM
    // ----------------------------------------------------------------------

    public function test_staff_file_download_refuses_a_foreign_studios_file(): void
    {
        foreach ([$this->beta->internalFile->id, $this->beta->sharedFile->id] as $fileId) {
            $status = $this->actingAs($this->alpha->user('owner'))
                ->get(route('files.download', ['file' => $fileId]))
                ->status();

            $this->assertContains($status, [403, 404], "İşçi yad studiyanın faylını endirdi (status {$status}).");
        }
    }

    /**
     * Təqvim lenti: beta-nın markerli tapşırığı, mərhələsi, ödənişi, hesab-
     * fakturası və görüşü alpha-nın təqvimində olmamalıdır.
     */
    public function test_calendar_feed_holds_no_foreign_studio_event(): void
    {
        $this->seedBetaMarkers();

        $response = $this->actingAs($this->alpha->user('owner'))->getJson(route('calendar.events', [
            'start' => now()->subYear()->toDateString(),
            'end' => now()->addYear()->toDateString(),
        ]));

        $response->assertOk();

        $this->assertStringNotContainsStringQuietly(
            self::MARK,
            $response->getContent(),
            'Təqvim yad studiyanın hadisəsini göstərdi.',
        );
    }

    // ----------------------------------------------------------------------
    // 6. İSTİFADƏÇİ VƏ ROLLAR
    // ----------------------------------------------------------------------

    public function test_an_owner_cannot_see_or_edit_a_foreign_studios_user(): void
    {
        $foreignUser = $this->beta->user('designer');

        app(TenantContext::class)->actingAs($this->alpha->tenant->id, function () use ($foreignUser) {
            $this->assertNull(User::query()->find($foreignUser->id));
            $this->assertNull(User::withTrashed()->find($foreignUser->id));
        });

        $status = $this->actingAs($this->alpha->user('owner'))
            ->get(UserResource::getUrl('edit', ['record' => $foreignUser->id], panel: 'app'))
            ->status();

        $this->assertContains($status, [403, 404], "Alpha sahibkarı beta işçisini açdı (status {$status}).");
    }

    public function test_portal_accounts_are_isolated_too(): void
    {
        app(TenantContext::class)->actingAs($this->alpha->tenant->id, function () {
            $this->assertNull(ClientUser::query()->find($this->beta->portalUser->id));
            $this->assertNull(ClientUser::query()->find($this->beta->secondPortalUser->id));
        });
    }

    /**
     * Deaktiv edilmiş studiya HƏR İKİ qapıda bağlanmalıdır — əks halda beta
     * söndürülsə belə onun müştərisi portalda qalır.
     */
    public function test_a_deactivated_studio_is_closed_on_both_front_doors(): void
    {
        Tenant::query()->whereKey($this->beta->tenant->id)->update(['active' => false]);

        $portal = $this->actingAs($this->beta->portalUser, 'customer')->get(route('portal.home'));
        $this->assertSame(403, $portal->status(), 'Deaktiv studiyanın müştərisi portala girdi.');

        // Beta-nın işçisi də panelə buraxılmamalıdır.
        $panel = $this->actingAs($this->beta->user('owner'))
            ->get(Dashboard::getUrl(panel: 'app'));
        $this->assertNotSame(200, $panel->status(), 'Deaktiv studiyanın işçisi panelə girdi.');
    }

    // ----------------------------------------------------------------------
    // 7. AVTOMATLAŞDIRMA — TAPILAN SIZMALAR
    // ----------------------------------------------------------------------

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: bir studiyanın avtomatlaşdırma açarı
     * ARTIQ o biri studiyanın bildirişlərini söndürmür.
     *
     * Köhnə davranış (baq): `AutomationRule::$fillable` siyahısında `tenant_id`
     * YOX idi. `AutomationRuleResource`-un ToggleColumn-u copy-on-write üçün
     * `updateOrCreate(['tenant_id' => $tenantId, 'code' => …], …)` çağırır,
     * `updateOrCreate()` isə yeni sətri `firstOrNew()` → `fill()` ilə qurur və
     * `fill()` `$fillable`-dan kənar açarı SƏSSİZCƏ atırdı. Nəticədə studiya
     * override-i yox, `tenant_id = NULL` olan İKİNCİ PLATFORMA sətri yaranırdı
     * ((tenant_id, code) unikal indeksi NULL-ları fərqli saydığı üçün insert
     * keçirdi). `AutomationEngine::isEnabled()` platforma sətirlərini
     * `pluck('enabled', 'code')` ilə oxuyur — eyni kod üçün SONUNCU sətir qalib
     * gəlirdi, yəni beta-nın «söndür» qərarı alfa-ya da tətbiq olunurdu.
     *
     * Düzəliş: `AutomationRule::$fillable`-a `tenant_id` əlavə olundu, VƏ
     * `AutomationRuleResource`-un ToggleColumn-u override sətrini `firstOrNew`
     * + `forceFill(['tenant_id' => $tenantId, …])` ilə yazır (yəni `$fillable`
     * dəyişsə belə sahiblik itmir). Qalmış dublikat NULL sətirləri
     * `2026_09_27_020000_dedupe_platform_automation_rules` miqrasiyası təmizləyir.
     *
     * İndi gözlənilən VƏ faktiki davranış:
     *  - beta üçün `tenant_id = beta` override sətri yaranır və sönülüdür;
     *  - platformanın `tenant_id = NULL` sətri TOXUNULMAZ (hələ də aktiv) qalır;
     *  - dublikat `tenant_id = NULL` sətri YARANMIR;
     *  - alfa üçün rule-9 AKTİV qalır, beta üçün SÖNÜR.
     */
    public function test_one_studios_automation_toggle_leaves_the_other_studio_untouched(): void
    {
        $ctx = app(TenantContext::class);

        // Platforma səviyyəsində rule-9 AKTİVDİR.
        $platform = new AutomationRule;
        $platform->forceFill([
            'tenant_id' => null, 'code' => 'rule-9', 'name' => 'Gecikmiş tapşırıq',
            'trigger' => 'task.overdue', 'priority' => 'medium', 'enabled' => true,
        ])->save();

        // Beta-nın sahibkarı paneldə açarı söndürür — resursdakı copy-on-write
        // məntiqinin EYNİSİ.
        AutomationRule::updateOrCreate(
            ['tenant_id' => $this->beta->tenant->id, 'code' => $platform->code],
            $platform->only(['name', 'trigger', 'priority', 'conditions', 'actions']) + ['enabled' => false],
        );

        // 1) Beta-nın öz override sətri yarandı — həm də məhz sönülü.
        $betaRules = AutomationRule::query()
            ->where('tenant_id', $this->beta->tenant->id)
            ->where('code', 'rule-9')
            ->get();

        $this->assertCount(1, $betaRules, 'Beta üçün tam bir override sətri gözlənilirdi.');
        $this->assertFalse($betaRules->first()->enabled, 'Beta-nın override sətri sönülü yazılmadı.');

        // 2) Platforma sətri toxunulmazdır və dublikat NULL sətri yaranmayıb.
        $platformRules = AutomationRule::query()
            ->whereNull('tenant_id')
            ->where('code', 'rule-9')
            ->get();

        $this->assertCount(1, $platformRules, 'Dublikat `tenant_id = NULL` platforma sətri yarandı.');
        $this->assertTrue($platformRules->first()->enabled, 'Beta-nın açarı platforma sətrini söndürdü.');

        // 3) Nəticə: alfa-nın qaydası AKTİV qalır, yalnız beta-nınkı sönür.
        app(AutomationEngine::class)->flush();

        $enabledFor = fn (int $tenantId) => $ctx->actingAs($tenantId, function () {
            app(AutomationEngine::class)->flush();

            return app(AutomationEngine::class)->isEnabled('rule-9');
        });

        $this->assertTrue(
            $enabledFor($this->alpha->tenant->id),
            'Beta-nın açarı alfa-nın rule-9 qaydasını söndürdü — sızma geri qayıdıb.',
        );
        $this->assertFalse(
            $enabledFor($this->beta->tenant->id),
            'Beta öz qaydasını söndürdü, amma override tətbiq olunmadı.',
        );
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: planlaşdırıcıdan işləyən
     * `tasks:notify-deadlines` ARTIQ studiyanın öz avtomatlaşdırma override-ini
     * nəzərə alır.
     *
     * Köhnə davranış (baq): əmr CLI-da tenant konteksti QURMADAN işləyirdi.
     * Kontekst boş olduğuna görə `AutomationEngine::isEnabled()` yalnız
     * `tenant_id IS NULL` sətirlərini oxuyurdu — studiyanın öz «söndür» sətri
     * heç vaxt tətbiq olunmurdu və beta rule-9-u söndürsə belə beta-nın
     * işçisinə gecikmə bildirişi gedirdi.
     *
     * Düzəliş: əmr `RunAutomationTick` etalonu ilə hər AKTİV studiya üçün
     * `TenantContext::actingAs()` ilə ayrıca keçid edir və hər keçiddə
     * `AutomationEngine::flush()` çağırır (qayda xəritəsi studiyaya bağlıdır).
     * Tək studiyalı quraşdırmada scope-suz köhnə keçid saxlanılıb.
     *
     * İndi gözlənilən VƏ faktiki davranış: beta rule-9-u söndürübsə, beta-nın
     * işçisinə bildiriş GETMİR; alfa-nınkına gedir.
     *
     * DİQQƏT — əmr artıq studiya-studiya gəzdiyinə görə `tenant_id = null` olan
     * sətirlər heç bir keçidə düşmür. Ona görə testdəki tapşırıqlar mütləq
     * `TenantContext::actingAs()` içində yaradılır/yenilənir — prodda hər sətir
     * məhz belə yaranır (`StudioWorld` da dünyanı actingAs içində qurur).
     */
    public function test_deadline_command_honours_a_studios_own_automation_override(): void
    {
        $ctx = app(TenantContext::class);

        (new AutomationRule)->forceFill([
            'tenant_id' => null, 'code' => 'rule-9', 'name' => 'Gecikmiş tapşırıq',
            'trigger' => 'task.overdue', 'priority' => 'medium', 'enabled' => true,
        ])->save();

        // Beta paneldən öz (tenant-lı) override-ini yazır.
        (new AutomationRule)->forceFill([
            'tenant_id' => $this->beta->tenant->id, 'code' => 'rule-9',
            'name' => 'Gecikmiş tapşırıq', 'trigger' => 'task.overdue',
            'priority' => 'medium', 'enabled' => false,
        ])->save();

        foreach ([$this->alpha, $this->beta] as $world) {
            $ctx->actingAs($world->tenant->id, fn () => Task::query()
                ->whereKey($world->task->id)
                ->update(['deadline' => now()->subDay()->toDateString()]));
        }

        Notification::fake();

        $this->artisan('tasks:notify-deadlines')->assertSuccessful();

        // Alfa qaydanı söndürməyib — bildiriş həm icraçıya, həm menecerə gedir.
        Notification::assertSentTo($this->alpha->user('designer'), TaskOverdue::class);
        Notification::assertSentTo($this->alpha->user('project_manager'), TaskOverdue::class);

        // Beta söndürüb — HEÇ KİMƏ getmir.
        Notification::assertNotSentTo($this->beta->user('designer'), TaskOverdue::class);
        Notification::assertNotSentTo($this->beta->user('project_manager'), TaskOverdue::class);
    }

    // ----------------------------------------------------------------------
    // 8. AQREQATLAR — PUL RƏQƏMLƏRİ QARIŞMIR
    // ----------------------------------------------------------------------

    /**
     * Ən təhlükəli sızma növü: SUM/COUNT-lara yad studiyanın pulu qarışır.
     * Beta-ya böyük, «tanınan» məbləğ yazılır; alpha-nın portfelində o məbləğ
     * heç bir yerdə görünməməlidir.
     */
    public function test_portfolio_finance_aggregates_never_mix_the_two_studios(): void
    {
        $ctx = app(TenantContext::class);
        $marker = 987654.00;

        $ctx->actingAs($this->beta->tenant->id, function () use ($marker) {
            $this->beta->project->payments()->create([
                'title' => self::MARK.' ödəniş', 'amount' => $marker,
                'status' => 'paid', 'due_date' => now()->subDay(),
            ]);

            $this->beta->project->update(['budget_plan' => $marker]);

            Expense::create([
                'project_id' => $this->beta->project->id,
                'category' => 'other', 'vendor' => self::MARK.' satıcı',
                'amount' => $marker, 'date' => now()->subDay()->toDateString(),
                'status' => 'approved',
            ]);
        });

        $alpha = $ctx->actingAs($this->alpha->tenant->id, fn () => app(ProfitabilityService::class)->portfolio());
        $beta = $ctx->actingAs($this->beta->tenant->id, fn () => app(ProfitabilityService::class)->portfolio());

        $this->assertLessThan($marker, $alpha['collected'], 'Alpha-nın «yığılmış gəlir»inə beta-nın ödənişi qarışdı.');
        $this->assertLessThan($marker, $alpha['cost'], 'Alpha-nın xərcinə beta-nın xərci qarışdı.');
        $this->assertLessThan($marker, $alpha['projected'], 'Alpha-nın proqnozuna beta-nın büdcəsi qarışdı.');

        $this->assertGreaterThanOrEqual($marker, $beta['collected']);
        $this->assertGreaterThanOrEqual($marker, $beta['cost']);
    }

    /** Layihə üzrə rentabellik: alpha kontekstində beta layihəsi ümumiyyətlə yoxdur. */
    public function test_profitability_table_lists_only_the_viewers_studio(): void
    {
        $ids = app(TenantContext::class)->actingAs(
            $this->alpha->tenant->id,
            fn () => Project::query()->pluck('id')->all(),
        );

        $this->assertNotContains($this->beta->project->id, $ids);
        $this->assertNotContains($this->beta->otherProject->id, $ids);
        $this->assertCount(2, $ids, 'Alpha tam olaraq öz iki layihəsini görməlidir.');
    }

    /** Dashboard vidjetləri (sahibkar statistikası) yad rəqəm göstərmir. */
    public function test_owner_dashboard_shows_no_foreign_studio_figure(): void
    {
        app(TenantContext::class)->actingAs($this->beta->tenant->id, function () {
            $this->beta->project->payments()->create([
                'title' => self::MARK.' marker', 'amount' => 123456.78,
                'status' => 'paid', 'due_date' => now()->subDay(),
            ]);
        });

        $response = $this->actingAs($this->alpha->user('owner'))
            ->get(Dashboard::getUrl(panel: 'app'));

        $response->assertOk();

        foreach (['123 456.78', '123456.78', self::MARK, $this->beta->project->name] as $needle) {
            $this->assertStringNotContainsStringQuietly(
                $needle,
                $response->getContent(),
                "Dashboard yad studiyanın «{$needle}» məlumatını göstərdi.",
            );
        }
    }

    // ----------------------------------------------------------------------
    // Köməkçilər
    // ----------------------------------------------------------------------

    /**
     * HTML/JSON yoxlamaları üçün axtarılan sətirlər. StudioWorld-un ümumi
     * adları («Planlaşdırma», «Avans», «Müqavilə») HƏR İKİ dünyada eynidir —
     * ona görə yalnız beta-ya məxsus unikal sətirlər istifadə olunur.
     *
     * @return list<string>
     */
    private function foreignNeedles(): array
    {
        return [
            self::MARK,
            $this->beta->project->name,
            $this->beta->otherProject->name,
            $this->beta->client->name,
            $this->beta->user('designer')->email,
            $this->beta->portalUser->email,
        ];
    }

    /**
     * Beta dünyasına HƏR resurs modeli üzrə unikal markerli sətir əlavə edir —
     * həm resurs əhatəsini genişləndirmək, həm də HTML-də axtarılacaq unikal
     * sətir yaratmaq üçün.
     */
    private function seedBetaMarkers(): void
    {
        if ($this->markersSeeded) {
            return;
        }

        app(TenantContext::class)->actingAs($this->beta->tenant->id, function () {
            $b = $this->beta;

            $b->task->update(['title' => self::MARK.' tapşırıq', 'deadline' => now()->addDays(3)]);
            $b->stage->update(['name' => self::MARK.' mərhələ', 'date_plan_end' => now()->addDays(5)]);
            $b->payment->update(['title' => self::MARK.' ödəniş', 'due_date' => now()->addDays(2)]);
            $b->clientDocument->update(['title' => self::MARK.' sənəd']);
            $b->internalFile->update(['title' => self::MARK.' daxili fayl']);
            $b->sharedFile->update(['title' => self::MARK.' paylaşılan fayl']);
            $b->procurementItem->update(['name' => self::MARK.' mebel']);
            $b->budgetLine->update(['work_type' => self::MARK.' iş növü']);

            Lead::create([
                'first_name' => self::MARK, 'last_name' => 'Lid',
                'email' => 'lead@beta.test', 'status' => 'new',
            ]);

            $supplier = Supplier::create([
                'name' => self::MARK.' təchizatçı', 'category' => 'mebel',
            ]);

            Expense::create([
                'project_id' => $b->project->id, 'category' => 'other',
                'vendor' => self::MARK.' satıcı', 'amount' => 100,
                'date' => now()->toDateString(), 'status' => 'pending',
            ]);

            Invoice::create([
                'project_id' => $b->project->id, 'client_id' => $b->client->id,
                'number' => self::MARK.'-INV-1', 'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(10)->toDateString(),
                'total' => 1000, 'status' => 'issued',
            ]);

            Meeting::create([
                'project_id' => $b->project->id, 'title' => self::MARK.' görüş',
                'starts_at' => now()->addDays(4),
            ]);

            PurchaseOrder::create([
                'supplier_id' => $supplier->id, 'project_id' => $b->project->id,
                'order_date' => now()->toDateString(), 'total' => 500,
                'status' => 'draft', 'items' => [['name' => self::MARK.' sifariş']],
            ]);

            TimeEntry::create([
                'user_id' => $b->user('designer')->id, 'project_id' => $b->project->id,
                'duration_minutes' => 60, 'hourly_cost_snapshot' => 10,
                'comment' => self::MARK.' vaxt', 'started_at' => now()->subHour(),
                'ended_at' => now(),
            ]);

            Document::create([
                'project_id' => $b->project->id, 'type' => 'other',
                'title' => self::MARK.' ikinci sənəd',
                'file_path' => 'docs/beta-marker.pdf', 'visible_to_client' => true,
            ]);
        });

        $this->markersSeeded = true;
    }

    /** Portal fayl marşrutları üçün beta tərəfdə lazım olan əlavə qeydlər. */
    private function betaExtras(): array
    {
        return app(TenantContext::class)->actingAs($this->beta->tenant->id, function () {
            $this->beta->procurementItem->update(['photo_path' => 'proc/beta.jpg']);

            return [
                'diary' => DiaryEntry::create([
                    'project_id' => $this->beta->project->id,
                    'author_user_id' => $this->beta->user('designer')->id,
                    'body' => self::MARK.' gündəlik',
                    'photos' => ['diary/beta.jpg'],
                    'published_at' => now()->subDay(),
                ]),
                'chatFile' => ChatMessage::create([
                    'project_id' => $this->beta->project->id,
                    'author_type' => 'user',
                    'author_id' => $this->beta->user('project_manager')->id,
                    'body' => null,
                    'kind' => ChatMessage::KIND_FILE,
                    'attachment_path' => 'chat/beta.pdf',
                    'attachment_name' => 'beta.pdf',
                    'attachment_mime' => 'application/pdf',
                    'attachment_size' => 10,
                ]),
            ];
        });
    }

    /** @return list<class-string<Model>> */
    private function tenantModels(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (class_exists($class) && $this->usesTenantTrait($class)) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    private function usesTenantTrait(string $class): bool
    {
        return in_array(
            BelongsToTenant::class,
            class_uses_recursive($class),
            true,
        );
    }

    /** @return list<string> */
    private function allTables(): array
    {
        return array_map(
            fn ($t) => is_array($t) ? $t['name'] : $t->name,
            Schema::getTables(),
        );
    }

    private function modelForTable(string $table): ?string
    {
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (class_exists($class)
                && is_subclass_of($class, Model::class)
                && (new $class)->getTable() === $table) {
                return $class;
            }
        }

        return null;
    }

    /** @return list<class-string<\Filament\Resources\Resource>> */
    private function panelResources(): array
    {
        return array_values(Filament::getPanel('app')->getResources());
    }

    /** @return list<class-string<Page>> */
    private function panelPages(): array
    {
        return array_values(Filament::getPanel('app')->getPages());
    }
}
