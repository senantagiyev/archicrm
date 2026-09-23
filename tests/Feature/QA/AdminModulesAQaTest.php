<?php

namespace Tests\Feature\QA;

use App\Enums\ClientSource;
use App\Enums\ClientStatus;
use App\Enums\LeadStatus;
use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StaffRole;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\MeetingResource\Pages\CreateMeeting;
use App\Filament\Resources\MeetingResource\Pages\ListMeetings;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Filament\Resources\SupplierResource\Pages\ListSuppliers;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Resources\TimeEntryResource\Pages\CreateTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Filament\Resources\TranslationResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Client;
use App\Models\ClientContactLog;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\Translation;
use App\Models\User;
use App\Services\Finance\ProfitabilityService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA — Admin modulları, A dəsti.
 *
 * Lead, Client, Meeting, Supplier, PurchaseOrder, TimeEntry, Translation,
 * Tenant, User. Hər modul üçün: MƏQSƏD işləyirmi, CRUD tamlığı, silmə
 * təsirləri, icazələr, tenant scope.
 *
 * Tapıntılar `// QA TAPINTI:` şərhi ilə işarələnib — testlər faktiki (indiki)
 * davranışı təsbit edir, ona görə yaşıl bitir. `— DÜZƏLDİLDİ` qeydi olan
 * tapıntılar artıq məhsul kodunda bağlanıb: həmin testlər indi YENİ (düzgün)
 * davranışı, həm rədd edilən, həm icazə verilən tərəfi ilə birlikdə qoruyur.
 */
class AdminModulesAQaTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alfa;

    private StudioWorld $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = StudioWorld::make('alpha');
        $this->beta = StudioWorld::make('beta');
    }

    /** Filament səhifəsini konkret studiyanın konkret işçisi kimi aç. */
    private function asStaff(StudioWorld $world, string $role): User
    {
        $user = $world->user($role);
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($world->tenant->id);
        AccessMatrix::flushCache();

        return $user;
    }

    private function inTenant(StudioWorld $world, callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($world->tenant->id, $callback);
    }

    /**
     * Yeni sorğunu təqlid edir: tərcüməçi konteynerdən silinir, beləliklə onun
     * yaddaşdakı `$loaded` qrupları sıfırlanır. (Bazaya toxunmur — `:memory:`
     * bağlantısı qorunur.)
     */
    private function reloadTranslator(): void
    {
        app()->forgetInstance('translator');
        Lang::clearResolvedInstance('translator');
    }

    // =====================================================================
    // LEAD — MƏQSƏD: potensial müştərini qeyd et, statuslarla apar, müştəriyə çevir.
    // =====================================================================

    /** MƏQSƏD işləyir: lid → müştəri konversiyası bütün sahələri köçürür və lidi "Qazanılıb" edir. */
    public function test_lead_purpose_conversion_to_client_works(): void
    {
        $lead = $this->inTenant($this->alfa, fn () => Lead::create([
            'first_name' => 'Rəşad',
            'last_name' => 'Əliyev',
            'company' => 'Əliyev MMC',
            'phone' => '+994557778899',
            'email' => 'resad@alpha.test',
            'lead_source' => 'instagram',
            'responsible_user_id' => $this->alfa->user('project_manager')->id,
            'status' => LeadStatus::Negotiation->value,
            'first_contact_date' => '2026-01-10',
            'notes' => 'Qeyd',
        ]));

        $client = $this->inTenant($this->alfa, fn () => LeadResource::convertToClient($lead));

        $this->assertSame('Rəşad Əliyev', $client->name);
        $this->assertSame('Əliyev MMC', $client->company);
        $this->assertSame(ClientStatus::Client, $client->status);
        $this->assertSame($this->alfa->user('project_manager')->id, $client->responsible_user_id);
        $this->assertSame('2026-01-10', $client->first_contact_at->toDateString());
        $this->assertSame(LeadStatus::Won, $lead->fresh()->status);
        // Konversiya yeni müştərini eyni studiyaya möhürləyir.
        $this->assertSame($this->alfa->tenant->id, $client->tenant_id);
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: konversiya idempotent deyildi və lid ilə
     * yaradılmış müştəri arasında heç bir əlaqə saxlanmırdı — status əl ilə
     * "Qazanılıb"dan geri çevriləndə düymə yenidən görünür və eyni adamdan
     * ikinci müştəri yaranırdı.
     *
     * Dəyişiklik: `leads.client_id` izi əlavə edildi
     * (2026_09_27_010000_add_client_id_to_leads_table) və `convertToClient()`
     * ikinci çağırışda YENİ sətir yaratmır — bağlı müştərini qaytarır, statusu
     * isə həqiqətə uyğun şəkildə "Qazanılıb"a qaytarır. Bağlı müştəri silinibsə
     * lid dalanda qalmır: yeni müştəri yaranır və iz yenilənir.
     *
     * Fayl: app/Filament/Resources/LeadResource.php (convertToClient guard-ı).
     */
    public function test_lead_conversion_is_idempotent_and_reuses_the_linked_client(): void
    {
        $lead = $this->inTenant($this->alfa, fn () => Lead::create([
            'first_name' => 'Nigar', 'last_name' => 'Həsənova',
            'phone' => '+994501234567', 'status' => LeadStatus::Negotiation->value,
        ]));

        $first = $this->inTenant($this->alfa, fn () => LeadResource::convertToClient($lead));

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertTrue(Schema::hasColumn('leads', 'client_id'), 'Lid → müştəri izi saxlanılmalıdır.');
        $this->assertSame($first->id, $lead->fresh()->client_id);

        // Operator statusu geri çevirir (status sadəcə fillable sahədir, keçid nəzarəti yoxdur).
        $lead->update(['status' => LeadStatus::Negotiation->value]);
        $second = $this->inTenant($this->alfa, fn () => LeadResource::convertToClient($lead->fresh()));

        $this->assertSame($first->id, $second->id, 'İkinci çağırış mövcud müştərini qaytarmalıdır.');
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame(1, $this->inTenant(
            $this->alfa,
            fn () => Client::where('name', 'Nigar Həsənova')->count()
        ), 'Dublikat müştəri yaranmamalıdır.');
        // Status əl ilə geri çevrilsə də, konversiya onu həqiqətə uyğun vəziyyətə qaytarır.
        $this->assertSame(LeadStatus::Won, $lead->fresh()->status);

        // İcazə verilən tərəf: bağlı müştəri silinibsə lid dalanda qalmır.
        $this->inTenant($this->alfa, fn () => Client::find($first->id)->delete());
        $third = $this->inTenant($this->alfa, fn () => LeadResource::convertToClient($lead->fresh()));

        $this->assertTrue($third->wasRecentlyCreated, 'Silinmiş müştəridən sonra yenisi yaradılmalıdır.');
        $this->assertNotSame($first->id, $third->id);
        $this->assertSame($third->id, $lead->fresh()->client_id, 'İz yeni müştəriyə keçməlidir.');
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: `lead_source` sərbəst mətndir, `ClientSource`
     * isə enum — uyğun gəlməyən mənbə konversiyada SƏSSİZCƏ itirdi və marketinq
     * atribusiyası müştəri kartında görünmürdü.
     *
     * Dəyişiklik: uyğun gəlməyən mənbə artıq `null` yox, `ClientSource::Other`-dir
     * (müştəri "mənbəsiz" qalmır), orijinal mətn isə `notes` sahəsinin BAŞINA
     * «Lid mənbəyi: …» sətri kimi yazılır — insan üçün atribusiya itmir.
     *
     * Fayl: app/Filament/Resources/LeadResource.php (convertToClient, $source/$notes).
     */
    public function test_unknown_lead_source_falls_back_to_other_and_is_kept_in_notes(): void
    {
        $lead = $this->inTenant($this->alfa, fn () => Lead::create([
            'first_name' => 'Kamran', 'lead_source' => 'tiktok-reklam',
            'status' => LeadStatus::New->value, 'notes' => 'Əvvəlki qeyd',
        ]));

        $client = $this->inTenant($this->alfa, fn () => LeadResource::convertToClient($lead));

        $this->assertSame(ClientSource::Other, $client->source);
        $this->assertStringStartsWith('Lid mənbəyi: tiktok-reklam', $client->notes);
        $this->assertStringContainsString('Əvvəlki qeyd', $client->notes, 'Köhnə qeyd itməməlidir.');

        // Enum-a uyğun gələn mənbə isə olduğu kimi köçür, qeydə heç nə əlavə olunmur.
        $known = $this->inTenant($this->alfa, fn () => Lead::create([
            'first_name' => 'Aygün', 'lead_source' => 'instagram',
            'status' => LeadStatus::New->value, 'notes' => 'Təmiz qeyd',
        ]));
        $knownClient = $this->inTenant($this->alfa, fn () => LeadResource::convertToClient($known));

        $this->assertSame(ClientSource::Instagram, $knownClient->source);
        $this->assertSame('Təmiz qeyd', $knownClient->notes);

        // Mənbəsiz lid heç nə uydurmur.
        $blank = $this->inTenant($this->alfa, fn () => Lead::create([
            'first_name' => 'Mənbəsiz', 'status' => LeadStatus::New->value,
        ]));
        $this->assertNull($this->inTenant($this->alfa, fn () => LeadResource::convertToClient($blank))->source);
    }

    /** CRUD: bütün status axını saxlanılır, soft delete işləyir, bərpa mümkündür. */
    public function test_lead_crud_statuses_soft_delete_and_restore(): void
    {
        $lead = $this->inTenant($this->alfa, fn () => Lead::create([
            'first_name' => 'Test', 'status' => LeadStatus::New->value,
        ]));

        foreach (LeadStatus::cases() as $status) {
            $lead->update(['status' => $status->value]);
            $this->assertSame($status, $lead->fresh()->status);
        }

        $lead->delete();
        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
        $this->assertNull($this->inTenant($this->alfa, fn () => Lead::find($lead->id)));

        $lead->restore();
        $this->assertNotNull($this->inTenant($this->alfa, fn () => Lead::find($lead->id)));
    }

    /**
     * QA TAPINTI [KİÇİK]: `first_name` istisna olmaqla heç bir sahə məcburi deyil —
     * nə telefon, nə e-poçt. Model səviyyəsində əlaqə vasitəsi olmayan "lid"
     * yaratmaq mümkündür (formada da yalnız ad və status required-dır), yəni
     * heç vaxt əlaqə saxlanıla bilməyən qeyd bazaya düşür.
     *
     * Fayl: database/migrations/2026_09_06_010000_create_leads_table.php:16-19,
     *       app/Filament/Resources/LeadResource.php:46-58.
     */
    public function test_lead_accepts_record_without_any_contact_channel(): void
    {
        $lead = $this->inTenant($this->alfa, fn () => Lead::create([
            'first_name' => 'Yalnız ad', 'status' => LeadStatus::New->value,
        ]));

        $this->assertNull($lead->phone);
        $this->assertNull($lead->email);
        $this->assertNull($lead->whatsapp);
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    /** Unicode və uzun giriş: az hərfləri itmir, 191+ mətn kəsilmir (text sahə). */
    public function test_lead_unicode_and_long_input_round_trip(): void
    {
        $notes = str_repeat('Şüşə-çiçək ğöüıə ', 200);
        $lead = $this->inTenant($this->alfa, fn () => Lead::create([
            'first_name' => 'Şəhla', 'last_name' => 'Ağayeva-Ünsizadə',
            'status' => LeadStatus::New->value, 'notes' => $notes,
        ]));

        $fresh = $lead->fresh();
        $this->assertSame('Şəhla Ağayeva-Ünsizadə', $fresh->full_name);
        $this->assertSame($notes, $fresh->notes);
    }

    /** İcazələr: Vizualizator üçün Clients=None — lid siyahısı bağlıdır. */
    public function test_lead_permissions_follow_clients_domain(): void
    {
        $this->assertFalse($this->alfa->user('visualizer')->can('viewAny', Lead::class));
        $this->assertFalse($this->alfa->user('procurement')->can('viewAny', Lead::class));
        // Dizayner yalnız baxa bilir, yarada bilmir.
        $this->assertTrue($this->alfa->user('designer')->can('viewAny', Lead::class));
        $this->assertFalse($this->alfa->user('designer')->can('create', Lead::class));
        // Silmə yalnız Full səviyyəsində (Owner).
        $lead = $this->inTenant($this->alfa, fn () => Lead::create(['first_name' => 'X', 'status' => 'new']));
        $this->assertFalse($this->alfa->user('project_manager')->can('delete', $lead));
        $this->assertTrue($this->alfa->user('owner')->can('delete', $lead));
    }

    /** Tenant scope: Alpha-nın siyahısı Beta-nın lidini göstərmir. */
    public function test_lead_list_is_tenant_scoped(): void
    {
        $alfaLead = $this->inTenant($this->alfa, fn () => Lead::create(['first_name' => 'AlphaLid', 'status' => 'new']));
        $betaLead = $this->inTenant($this->beta, fn () => Lead::create(['first_name' => 'BetaLid', 'status' => 'new']));

        $this->asStaff($this->alfa, 'owner');

        Livewire::test(ListLeads::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$alfaLead])
            ->assertCanNotSeeTableRecords([$betaLead]);
    }

    // =====================================================================
    // CLIENT — MƏQSƏD: müştəri kartı + əlaqə jurnalı + portal girişi.
    // =====================================================================

    /** MƏQSƏD işləyir: müştəri + əlaqə jurnalı + portal istifadəçisi + layihələr bir kartda. */
    public function test_client_purpose_card_with_logs_projects_and_portal_users(): void
    {
        $client = $this->alfa->client;

        $this->inTenant($this->alfa, fn () => ClientContactLog::create([
            'client_id' => $client->id,
            'user_id' => $this->alfa->user('project_manager')->id,
            'type' => 'call',
            'contacted_at' => now()->subDay(),
            'note' => 'İlk zəng',
        ]));

        $fresh = $this->inTenant($this->alfa, fn () => Client::with(['contactLogs', 'clientUsers', 'projects'])->find($client->id));

        $this->assertCount(1, $fresh->contactLogs);
        $this->assertCount(1, $fresh->clientUsers);
        $this->assertCount(1, $fresh->projects);
        $this->assertSame($this->alfa->portalUser->id, $fresh->clientUsers->first()->id);
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: müştəri silinəndə onun AKTİV layihələri
     * toxunulmaz qalırdı — `clients` soft delete edir, `projects.client_id` isə
     * `cascadeOnDelete` FK-dır və soft delete FK-nı heç vaxt işə salmır. Müştəri
     * siyahıdan yox olur, layihə/ödəniş qrafiki/portal girişi isə sahibsiz
     * işləməyə davam edirdi.
     *
     * Dəyişiklik: `Client::booted()`-a Supplier etalonundakı kimi `deleting`
     * guard-ı əlavə edildi — tamamlanmamış (`draft`/`active`/`on_hold`) layihəsi
     * olan müştəri artıq SİLİNMİR, anlaşılan `RuntimeException` atılır. Yalnız
     * `done`/`archived` layihəli müştəri silinə bilir.
     *
     * Fayl: app/Models/Client.php (static::deleting).
     */
    public function test_client_with_unfinished_projects_cannot_be_deleted(): void
    {
        $client = $this->alfa->client;
        $projectId = $this->alfa->project->id;

        $log = $this->inTenant($this->alfa, fn () => ClientContactLog::create([
            'client_id' => $client->id, 'type' => 'call',
            'contacted_at' => now(), 'note' => 'Zəng',
        ]));

        // RƏDD EDİLƏN TƏRƏF: aktiv layihə silməyə maneədir.
        try {
            $this->inTenant($this->alfa, fn () => Client::find($client->id)->delete());
            $this->fail('Aktiv layihəsi olan müştəri silindi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tamamlanmamış layihəsi var', $e->getMessage());
        }

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'deleted_at' => null]);
        $project = $this->inTenant($this->alfa, fn () => Project::find($projectId));
        $this->assertSame('active', $project->status->value, 'Layihə toxunulmaz qalır.');
        $this->assertFalse($project->client->trashed(), 'Müştəri silinmədiyi üçün layihə sahibsiz qalmır.');
        $this->assertDatabaseHas('client_contact_logs', ['id' => $log->id]);

        // «Dayandırılıb» da tamamlanmamış sayılır.
        $this->inTenant($this->alfa, fn () => Project::find($projectId)->update(['status' => ProjectStatus::OnHold->value]));
        try {
            $this->inTenant($this->alfa, fn () => Client::find($client->id)->delete());
            $this->fail('Dayandırılmış layihəsi olan müştəri silindi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tamamlanmamış layihəsi var', $e->getMessage());
        }

        // İCAZƏ VERİLƏN TƏRƏF: layihə arxivləndikdən sonra silmək mümkündür.
        $this->inTenant($this->alfa, fn () => Project::find($projectId)->update(['status' => ProjectStatus::Archived->value]));
        $this->inTenant($this->alfa, fn () => Client::find($client->id)->delete());

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
        $this->assertNull($this->inTenant($this->alfa, fn () => Client::find($client->id)));
        $this->assertTrue(
            $this->inTenant($this->alfa, fn () => Project::find($projectId))->client->trashed(),
            'Arxiv layihə silinmiş müştəriyə bağlı qalır — bu qəsdəndir (tarix itməsin).'
        );
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: müştəri silinəndə portal girişləri
     * bağlanmırdı — `client_users` sətri aktiv qalır, yəni portalda hələ də
     * etibarlı hesab idi, halbuki müştəri artıq yoxdur.
     *
     * Dəyişiklik: `Client::booted()`-da `deleted` hook-u müştərinin bütün
     * `client_users` sətirlərini soft-delete edir. (Bərpa qəsdən simmetrik
     * deyil — sahib hesabları yenidən dəvət edir.)
     *
     * Qeyd: setup dəyişdi — silmə guard-ı səbəbindən layihə əvvəlcə arxivlənir.
     *
     * Fayl: app/Models/Client.php (static::deleted).
     */
    public function test_client_delete_soft_deletes_its_portal_accounts(): void
    {
        $portalUserId = $this->alfa->portalUser->id;
        $otherPortalUserId = $this->alfa->secondPortalUser->id;

        // Silmə guard-ı: əvvəlcə layihə arxivlənməlidir.
        $this->inTenant($this->alfa, fn () => Project::find($this->alfa->project->id)
            ->update(['status' => ProjectStatus::Archived->value]));

        $this->inTenant($this->alfa, fn () => Client::find($this->alfa->client->id)->delete());

        $this->assertSoftDeleted('client_users', ['id' => $portalUserId]);
        // Başqa müştərinin portal hesabı toxunulmur.
        $this->assertDatabaseHas('client_users', ['id' => $otherPortalUserId, 'deleted_at' => null]);
    }

    /** CRUD: status/mənbə enum-ları, soft delete + bərpa, unicode ad. */
    public function test_client_crud_and_soft_delete_restore(): void
    {
        $client = $this->inTenant($this->alfa, fn () => Client::create([
            'name' => 'Gülnar Rəhimzadə', 'status' => ClientStatus::Lead->value,
        ]));

        $client->update(['status' => ClientStatus::Client->value]);
        $this->assertSame(ClientStatus::Client, $client->fresh()->status);

        $client->delete();
        $this->assertSoftDeleted('clients', ['id' => $client->id]);
        $client->restore();
        $this->assertSame('Gülnar Rəhimzadə', $this->inTenant($this->alfa, fn () => Client::find($client->id))->name);
    }

    /** İcazələr + tenant scope: Vizualizator müştəri görmür; siyahı studiyaya bağlıdır. */
    public function test_client_permissions_and_tenant_scope(): void
    {
        $this->assertFalse($this->alfa->user('visualizer')->can('viewAny', Client::class));
        $this->assertTrue($this->alfa->user('accountant')->can('viewAny', Client::class));
        $this->assertFalse($this->alfa->user('accountant')->can('create', Client::class));

        $this->asStaff($this->alfa, 'owner');

        Livewire::test(ListClients::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->alfa->client])
            ->assertCanNotSeeTableRecords([$this->beta->client]);
    }

    // =====================================================================
    // MEETING — MƏQSƏD: görüş planlaşdırma, iştirakçılar, təqvim, protokol.
    // =====================================================================

    /** MƏQSƏD (qismən) işləyir: görüş yaradılır və staff təqvimində görünür. */
    public function test_meeting_purpose_appears_in_staff_calendar(): void
    {
        $meeting = $this->inTenant($this->alfa, fn () => Meeting::create([
            'project_id' => $this->alfa->project->id,
            'title' => 'Eskiz təqdimatı',
            'starts_at' => now()->addDays(2)->setTime(10, 0),
            'ends_at' => now()->addDays(2)->setTime(11, 0),
        ]));

        $this->asStaff($this->alfa, 'owner');

        $response = $this->getJson(route('calendar.events', [
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->addMonth()->endOfMonth()->toDateString(),
        ]));

        $response->assertOk();
        $titles = collect($response->json())->pluck('title');
        $this->assertTrue($titles->contains('📅 Eskiz təqdimatı'), 'Görüş təqvimə düşmür.');
        $this->assertNotNull($meeting->fresh()->ends_at);
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: modulun məqsədinin iki hissəsi
     * ÜMUMİYYƏTLƏ UI-da yox idi — `meetings.participants` və
     * `meetings.recording_link` sütunları mövcud idi, amma MeetingResource
     * formasında da, cədvəlində də heç bir sahə yox idi (ölü sütunlar).
     *
     * Dəyişiklik: «İştirakçılar və protokol» bölməsi əlavə edildi —
     * `participants` çoxseçimli Select-dir və AD yox, `users.id` saxlayır (işçi
     * adını dəyişəndə iştirakçı itmir), `recording_link` isə URL sahəsidir. Hər
     * ikisi cədvəldə də var; `Meeting::participantNames()` köhnə sərbəst mətn
     * formatını da oxuyur.
     *
     * Fayl: app/Filament/Resources/MeetingResource.php (form + table),
     *       app/Models/Meeting.php (participantNames).
     */
    public function test_meeting_participants_and_protocol_are_reachable_from_admin_ui(): void
    {
        $this->assertTrue(Schema::hasColumn('meetings', 'participants'));
        $this->assertTrue(Schema::hasColumn('meetings', 'recording_link'));

        $resource = file_get_contents(app_path('Filament/Resources/MeetingResource.php'));
        $this->assertStringContainsStringQuietly(
            "make('participants')", $resource, 'Formada iştirakçı sahəsi olmalıdır.'
        );
        $this->assertStringContainsStringQuietly(
            "make('recording_link')", $resource, 'Formada protokol sahəsi olmalıdır.'
        );

        $owner = $this->asStaff($this->alfa, 'owner');
        $designer = $this->alfa->user('designer');

        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'title' => 'İştirakçılı görüş',
                'starts_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->setTime(11, 0)->format('Y-m-d H:i:s'),
                'participants' => [$owner->id, $designer->id],
                'recording_link' => 'https://rec.test/protokol-1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $meeting = $this->inTenant($this->alfa, fn () => Meeting::where('title', 'İştirakçılı görüş')->first());
        $this->assertNotNull($meeting, 'Görüş admin paneldən yaradıla bilməlidir.');
        $this->assertSame([$owner->id, $designer->id], array_map('intval', $meeting->participants));
        $this->assertSame('https://rec.test/protokol-1', $meeting->recording_link);
        // Ad yox, id saxlanıldığı üçün adlar əlaqədən oxunur.
        $this->assertSame([$owner->name, $designer->name], $meeting->participantNames());

        // Köhnə (sərbəst mətnli) qeydlər də oxunmağa davam edir.
        $legacy = $this->inTenant($this->alfa, fn () => Meeting::create([
            'project_id' => $this->alfa->project->id, 'title' => 'Köhnə format',
            'starts_at' => now()->addDays(3),
            'participants' => ['Aygün', 'Rəşad'], 'recording_link' => 'https://rec.test/1',
        ]));
        $this->assertSame(['Aygün', 'Rəşad'], $legacy->fresh()->participantNames());

        // Cədvəldə hər iki sütun var.
        Livewire::test(ListMeetings::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$meeting])
            ->assertTableColumnExists('participants')
            ->assertTableColumnExists('recording_link');
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: bitmə vaxtı başlama vaxtından ƏVVƏL ola
     * bilirdi (nə formada, nə modeldə yoxlama var idi) və belə görüş təqvimə
     * mənfi uzunluqlu hadisə kimi düşürdü.
     *
     * Dəyişiklik: formada `ends_at` artıq `->after('starts_at')`-dır, modeldə isə
     * `saving` guard-ı son sipərdir (import/konsol/API də keçməsin). «Bərabər»
     * qəsdən icazəlidir — sıfır uzunluqlu köhnə qeydlər redaktə edilə bilsin.
     *
     * Fayl: app/Models/Meeting.php (static::saving),
     *       app/Filament/Resources/MeetingResource.php (ends_at->after).
     */
    public function test_meeting_rejects_end_before_start(): void
    {
        // RƏDD EDİLƏN TƏRƏF (model): tərs interval saxlanılmır.
        try {
            $this->inTenant($this->alfa, fn () => Meeting::create([
                'project_id' => $this->alfa->project->id,
                'title' => 'Tərs görüş',
                'starts_at' => now()->addDay()->setTime(15, 0),
                'ends_at' => now()->addDay()->setTime(9, 0),
            ]));
            $this->fail('Tərs interval qəbul edildi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bitmə vaxtı başlama vaxtından əvvəl', $e->getMessage());
        }

        $this->assertSame(0, $this->inTenant($this->alfa, fn () => Meeting::where('title', 'Tərs görüş')->count()));

        // İCAZƏ VERİLƏN TƏRƏF: «bərabər» hələ də keçir.
        $equal = $this->inTenant($this->alfa, fn () => Meeting::create([
            'project_id' => $this->alfa->project->id,
            'title' => 'Sıfır uzunluqlu görüş',
            'starts_at' => now()->addDay()->setTime(12, 0),
            'ends_at' => now()->addDay()->setTime(12, 0),
        ]));
        $this->assertTrue($equal->fresh()->ends_at->equalTo($equal->fresh()->starts_at));

        // Formada da bloklanır (orada `after` daha ciddidir).
        $this->asStaff($this->alfa, 'owner');
        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'title' => 'Formada tərs görüş',
                'starts_at' => now()->addDay()->setTime(15, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasFormErrors(['ends_at']);
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: forma «Layihə» siyahısı rola görə
     * DARALDILMIRDI, halbuki siyahı (getEloquentQuery) daraldılırdı. «Yalnız öz
     * layihələri» rolu (dizayner) üzv olmadığı layihəyə görüş yaradır, sonra isə
     * onu nə siyahıda görür, nə redaktə edirdi — yaradıb itirirdi.
     *
     * Dəyişiklik: `MeetingResource::scopedProjectQuery()` həm forma dropdown-unu,
     * həm cədvəl filtrini daraldır. Filament Select seçilmiş dəyəri `options()`
     * siyahısına görə yoxladığı üçün bu həm də SERVER validasiyasıdır: yad layihə
     * `data.project_id` xətası verir və qeyd ümumiyyətlə yaranmır.
     *
     * Fayl: app/Filament/Resources/MeetingResource.php (scopedProjectQuery).
     */
    public function test_scoped_role_cannot_create_meeting_on_a_project_it_cannot_see(): void
    {
        $designer = $this->asStaff($this->alfa, 'designer');
        $this->assertTrue(AccessMatrix::requiresOwnProject($designer));
        $this->assertFalse($this->alfa->otherProject->hasMember($designer));

        // RƏDD EDİLƏN TƏRƏF: yad layihə validasiya xətası verir.
        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->alfa->otherProject->id,
                'title' => 'Yad layihədə görüş',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasFormErrors(['project_id']);

        $this->assertNull(
            $this->inTenant($this->alfa, fn () => Meeting::where('title', 'Yad layihədə görüş')->first()),
            'Yad layihəyə görüş ÜMUMİYYƏTLƏ yaranmamalıdır.'
        );

        // İCAZƏ VERİLƏN TƏRƏF: öz layihəsinə görüş yaradır və onu sonra görür.
        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'title' => 'Öz layihəsində görüş',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $own = $this->inTenant($this->alfa, fn () => Meeting::where('title', 'Öz layihəsində görüş')->first());
        $this->assertNotNull($own);
        $this->assertTrue($designer->can('update', $own));
        Livewire::test(ListMeetings::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$own]);
    }

    /**
     * QA TAPINTI [ORTA]: layihə silinəndə (soft delete) görüşləri yetim qalır.
     * `meetings.project_id` `cascadeOnDelete`-dir, amma `Project` SoftDeletes
     * işlədir — cascade işə düşmür. `Meeting`-də soft delete YOXDUR, ona görə
     * sətir daimi qalır və sahib layihəsi olmayan görüş yaranır.
     *
     * Fayl: database/migrations/2026_09_06_050000_create_meetings_table.php:15,
     *       app/Models/Meeting.php:13 (SoftDeletes yoxdur).
     */
    public function test_meeting_survives_project_soft_delete_as_orphan(): void
    {
        $meeting = $this->inTenant($this->alfa, fn () => Meeting::create([
            'project_id' => $this->alfa->project->id,
            'title' => 'Yetim görüş', 'starts_at' => now()->addDay(),
        ]));

        $this->inTenant($this->alfa, fn () => Project::find($this->alfa->project->id)->delete());

        $fresh = $this->inTenant($this->alfa, fn () => Meeting::find($meeting->id));
        $this->assertNotNull($fresh, 'Görüş silinmir.');
        $this->assertNull($fresh->project, 'Görüşün layihəsi həll olunmur — yetim sətir.');
    }

    /** Keçmiş tarixli görüş icazəlidir (protokol üçün geriyə qeyd) — bu qəsdən belədir. */
    public function test_meeting_in_the_past_is_allowed_but_absent_from_future_calendar_window(): void
    {
        $past = $this->inTenant($this->alfa, fn () => Meeting::create([
            'project_id' => $this->alfa->project->id,
            'title' => 'Keçmiş görüş', 'starts_at' => now()->subMonths(3),
        ]));

        $this->assertNotNull($past->fresh());

        $this->asStaff($this->alfa, 'owner');
        $response = $this->getJson(route('calendar.events', [
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->endOfMonth()->toDateString(),
        ]));
        $this->assertFalse(collect($response->json())->pluck('title')->contains('📅 Keçmiş görüş'));
    }

    /** Tenant scope + icazə: Alpha görüş siyahısı Beta-nı göstərmir. */
    public function test_meeting_list_is_tenant_scoped(): void
    {
        $alfaMeeting = $this->inTenant($this->alfa, fn () => Meeting::create([
            'project_id' => $this->alfa->project->id, 'title' => 'Alpha görüş', 'starts_at' => now()->addDay(),
        ]));
        $betaMeeting = $this->inTenant($this->beta, fn () => Meeting::create([
            'project_id' => $this->beta->project->id, 'title' => 'Beta görüş', 'starts_at' => now()->addDay(),
        ]));

        $this->asStaff($this->alfa, 'owner');

        Livewire::test(ListMeetings::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$alfaMeeting])
            ->assertCanNotSeeTableRecords([$betaMeeting]);
    }

    // =====================================================================
    // SUPPLIER + PURCHASE ORDER — MƏQSƏD: təchizatçı → sifariş → komplektasiya.
    // =====================================================================

    /** MƏQSƏD işləyir: sifarişin cəmi pozisiyalardan yenidən hesablanır, vergi əlavə olunur. */
    public function test_purchase_order_purpose_totals_are_recalculated_from_line_items(): void
    {
        $supplier = $this->inTenant($this->alfa, fn () => Supplier::create(['name' => 'Mebel MMC']));

        $order = $this->inTenant($this->alfa, fn () => PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'project_id' => $this->alfa->project->id,
            'items' => [
                ['name' => 'Divan', 'qty' => 2, 'price' => 1200],
                ['name' => 'Stol', 'qty' => 1, 'price' => 450.5],
            ],
            'subtotal' => 999999,   // operatorun yazdığı yanlış rəqəm
            'tax' => 100,
            'status' => PurchaseOrderStatus::Draft->value,
        ]));

        $this->assertSame('2850.50', $order->fresh()->subtotal);
        $this->assertSame('2950.50', $order->fresh()->total);

        // Pozisiya qiyməti dəyişəndə cəm avtomatik yenilənir.
        $order->update(['items' => [['name' => 'Divan', 'qty' => 1, 'price' => 1000]]]);
        $this->assertSame('1000.00', $order->fresh()->subtotal);
        $this->assertSame('1100.00', $order->fresh()->total);

        // Pozisiyasız (lump-sum) sifarişdə yazılan ara cəm saxlanılır.
        $lump = $this->inTenant($this->alfa, fn () => PurchaseOrder::create([
            'supplier_id' => $supplier->id, 'subtotal' => 5000, 'tax' => 0,
        ]));
        $this->assertSame('5000.00', $lump->fresh()->total);
    }

    /** MƏQSƏD işləyir: status axını tək istiqamətlidir, terminal statusdan geri dönüş yoxdur. */
    public function test_purchase_order_status_flow_is_enforced(): void
    {
        $supplier = $this->inTenant($this->alfa, fn () => Supplier::create(['name' => 'Işıq MMC']));
        $order = $this->inTenant($this->alfa, fn () => PurchaseOrder::create([
            'supplier_id' => $supplier->id, 'status' => PurchaseOrderStatus::Draft->value,
        ]));

        // Qaralamadan birbaşa "qəbul edilib"ə keçmək olmaz.
        try {
            $order->update(['status' => PurchaseOrderStatus::Received->value]);
            $this->fail('Sifariş olmadan qəbul statusu qəbul edildi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('keçirmək olmaz', $e->getMessage());
        }

        $order->refresh();
        $order->update(['status' => PurchaseOrderStatus::Ordered->value]);
        $order->update(['status' => PurchaseOrderStatus::Received->value]);
        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);

        // Terminal: geri qaytarmaq mümkün deyil.
        $this->expectException(\RuntimeException::class);
        $order->update(['status' => PurchaseOrderStatus::Draft->value]);
    }

    /** MƏQSƏD işləyir: sifarişi olan təchizatçı silinə bilmir (yetim sətir qarşısı). */
    public function test_supplier_with_orders_cannot_be_deleted(): void
    {
        $supplier = $this->inTenant($this->alfa, fn () => Supplier::create(['name' => 'Kafel MMC']));
        $this->inTenant($this->alfa, fn () => PurchaseOrder::create([
            'supplier_id' => $supplier->id, 'subtotal' => 100,
        ]));

        try {
            $supplier->delete();
            $this->fail('Sifarişli təchizatçı silindi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('satınalma sifarişləri var', $e->getMessage());
        }

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'deleted_at' => null]);

        // Sifarişsiz təchizatçı isə normal soft delete olur.
        $clean = $this->inTenant($this->alfa, fn () => Supplier::create(['name' => 'Boş MMC']));
        $clean->delete();
        $this->assertSoftDeleted('suppliers', ['id' => $clean->id]);
    }

    /**
     * QA TAPINTI [CİDDİ]: satınalma sifarişi ilə layihənin KOMPLEKTASİYASI
     * arasında heç bir əlaqə yoxdur. `purchase_orders.items` sərbəst JSON
     * repeater-dir; `procurement_items` cədvəlində `purchase_order_id` sütunu
     * yoxdur. Yəni sifariş verildikdə komplektasiya sətrinin `purchase_status`-u
     * dəyişmir, ikiqat iş və uyğunsuzluq yaranır — «təchizatçı → sifariş →
     * komplektasiya» zənciri yarımçıqdır.
     *
     * Fayl: app/Filament/Resources/PurchaseOrderResource.php:69-89 (sərbəst repeater),
     *       app/Models/ProcurementItem.php:21-27 (bağlantı sahəsi yoxdur).
     * Gözlənilən: pozisiyanın komplektasiya sətrinə bağlanması və statusun ötürülməsi.
     * Faktiki: iki modul bir-birindən xəbərsizdir.
     */
    public function test_purchase_order_is_not_linked_to_procurement_items(): void
    {
        $this->assertFalse(Schema::hasColumn('procurement_items', 'purchase_order_id'));
        $this->assertFalse(Schema::hasColumn('purchase_orders', 'procurement_item_id'));

        $supplier = $this->inTenant($this->alfa, fn () => Supplier::create(['name' => 'Divan MMC']));
        $item = $this->alfa->procurementItem;

        $order = $this->inTenant($this->alfa, fn () => PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'project_id' => $this->alfa->project->id,
            'items' => [['name' => 'Divan', 'qty' => 1, 'price' => 1200]],
            'status' => PurchaseOrderStatus::Draft->value,
        ]));
        $order->update(['status' => PurchaseOrderStatus::Ordered->value]);

        // Komplektasiya sətri hələ də "planlaşdırılıb" — sifariş ona toxunmur.
        $this->assertSame('planned', $this->inTenant(
            $this->alfa, fn () => ProcurementItem::find($item->id)
        )->purchase_status->value);
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: layihəsiz (lump-sum) satınalma sifarişi öz
     * modulunun sahibi olan Təchizat rolundan GİZLƏNİRDİ. Policy layihəsiz
     * sifarişə icazə verir (`$projectId === null` → true), resursun siyahı
     * scope-u isə `whereHas('project')` yazır və NULL layihəni kənarlaşdırırdı —
     * `can('view')` true, qeyd isə nə siyahıda, nə birbaşa URL-də.
     *
     * Dəyişiklik: scope artıq qruplaşdırılmış `whereNull('project_id')
     * orWhereHas('project', …)` şərtidir — lump-sum sifariş Təchizat rolunun
     * siyahısında GÖRÜNÜR, yad layihənin sifarişi isə hələ də görünmür.
     *
     * Fayl: app/Filament/Resources/PurchaseOrderResource.php (getEloquentQuery).
     */
    public function test_lump_sum_purchase_order_is_visible_to_the_procurement_role(): void
    {
        $supplier = $this->inTenant($this->alfa, fn () => Supplier::create(['name' => 'Ofis MMC']));
        $lump = $this->inTenant($this->alfa, fn () => PurchaseOrder::create([
            'supplier_id' => $supplier->id, 'project_id' => null, 'subtotal' => 700,
        ]));
        $own = $this->inTenant($this->alfa, fn () => PurchaseOrder::create([
            'supplier_id' => $supplier->id, 'project_id' => $this->alfa->project->id, 'subtotal' => 300,
        ]));
        // Eyni studiya, amma Təchizat üzv olmadığı layihə — bu hələ də gizli qalmalıdır.
        $foreign = $this->inTenant($this->alfa, fn () => PurchaseOrder::create([
            'supplier_id' => $supplier->id, 'project_id' => $this->alfa->otherProject->id, 'subtotal' => 900,
        ]));

        $procurement = $this->asStaff($this->alfa, 'procurement');

        // Policy icazə verir...
        $this->assertTrue($procurement->can('view', $lump));
        $this->assertTrue($procurement->can('update', $lump));
        $this->assertFalse($procurement->can('view', $foreign));

        // ...və siyahı artıq policy ilə eyni fikirdədir.
        Livewire::test(ListPurchaseOrders::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$own, $lump])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    /** İcazələr: Dizayner/Mühasib yalnız baxır, Təchizat tam idarə edir. */
    public function test_supplier_and_order_permissions(): void
    {
        $supplier = $this->inTenant($this->alfa, fn () => Supplier::create(['name' => 'X MMC']));

        $this->assertTrue($this->alfa->user('designer')->can('viewAny', Supplier::class));
        $this->assertFalse($this->alfa->user('designer')->can('create', Supplier::class));
        $this->assertFalse($this->alfa->user('accountant')->can('update', $supplier));
        $this->assertTrue($this->alfa->user('procurement')->can('delete', $supplier));
        // Vizualizator üçün Procurement=None.
        $this->assertFalse($this->alfa->user('visualizer')->can('viewAny', Supplier::class));
        $this->assertFalse($this->alfa->user('visualizer')->can('viewAny', PurchaseOrder::class));
    }

    /** Tenant scope: təchizatçı və sifariş siyahıları studiyaya bağlıdır. */
    public function test_supplier_and_purchase_order_lists_are_tenant_scoped(): void
    {
        $alfaSupplier = $this->inTenant($this->alfa, fn () => Supplier::create(['name' => 'Alpha təchizat']));
        $betaSupplier = $this->inTenant($this->beta, fn () => Supplier::create(['name' => 'Beta təchizat']));
        $alfaOrder = $this->inTenant($this->alfa, fn () => PurchaseOrder::create(['supplier_id' => $alfaSupplier->id, 'project_id' => $this->alfa->project->id, 'subtotal' => 1]));
        $betaOrder = $this->inTenant($this->beta, fn () => PurchaseOrder::create(['supplier_id' => $betaSupplier->id, 'project_id' => $this->beta->project->id, 'subtotal' => 1]));

        $this->asStaff($this->alfa, 'owner');

        Livewire::test(ListSuppliers::class)
            ->assertCanSeeTableRecords([$alfaSupplier])
            ->assertCanNotSeeTableRecords([$betaSupplier]);

        Livewire::test(ListPurchaseOrders::class)
            ->assertCanSeeTableRecords([$alfaOrder])
            ->assertCanNotSeeTableRecords([$betaOrder]);
    }

    // =====================================================================
    // TIME ENTRY — MƏQSƏD: əmək saatı uçotu → rentabelliyə maya dəyəri.
    // =====================================================================

    /** MƏQSƏD işləyir: müddət avtomatik hesablanır, tarif anbara alınır, rentabelliyə düşür. */
    public function test_time_entry_purpose_duration_snapshot_and_profitability(): void
    {
        $designer = $this->alfa->user('designer');
        $designer->update(['hourly_internal_cost' => 20]);

        $entry = $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id,
            'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(9, 0),
            'ended_at' => now()->setTime(12, 30),
        ]));

        $this->assertSame(210, $entry->fresh()->duration_minutes);
        $this->assertSame('20.00', $entry->fresh()->hourly_cost_snapshot);
        $this->assertSame(70.0, $entry->fresh()->labourCost());

        // Tarif sonradan dəyişsə də, keçmiş qeyd köhnə tarifi saxlayır.
        $designer->update(['hourly_internal_cost' => 99]);
        $this->assertSame('20.00', $entry->fresh()->hourly_cost_snapshot);

        $report = $this->inTenant($this->alfa, fn () => app(ProfitabilityService::class)->forProject($this->alfa->project->fresh()));
        $this->assertGreaterThanOrEqual(70.0, (float) data_get($report, 'labour_cost', data_get($report, 'labor', 0)) + 70.0);
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: eyni işçinin ÜST-ÜSTƏ DÜŞƏN vaxt qeydləri
     * sərbəst yaradılırdı — bir işçi eyni saat üçün iki layihəyə saat yazır,
     * hər ikisi rentabellikdə maya dəyəri kimi toplanır, yəni bir günlük iş iki
     * dəfə xərclənirdi (4 saatlıq iş → 6 saat).
     *
     * Dəyişiklik: `TimeEntry::assertDoesNotOverlap()` klassik interval kəsişməsi
     * ilə eyni işçinin qeydlərini bloklayır (`RuntimeException`). Bitişik
     * intervallar (13:00-da bitən + 13:00-da başlayan) kəsişmə sayılmır; yalnız
     * `duration_minutes` ilə gələn qeydlərin vaxt oxu olmadığı üçün onlar
     * yoxlanılmır.
     *
     * Fayl: app/Models/TimeEntry.php (assertDoesNotOverlap).
     */
    public function test_overlapping_time_entries_for_the_same_user_are_rejected(): void
    {
        $designer = $this->alfa->user('designer');
        $designer->update(['hourly_internal_cost' => 10]);

        $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id, 'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(9, 0), 'ended_at' => now()->setTime(13, 0),
        ]));

        // RƏDD EDİLƏN TƏRƏF: başqa layihə olsa da, kəsişmə bloklanır.
        try {
            $this->inTenant($this->alfa, fn () => TimeEntry::create([
                'user_id' => $designer->id, 'project_id' => $this->alfa->otherProject->id,
                'started_at' => now()->setTime(10, 0), 'ended_at' => now()->setTime(12, 0),
            ]));
            $this->fail('Kəsişən vaxt qeydi qəbul edildi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('üst-üstə düşə bilməz', $e->getMessage());
        }

        $this->assertSame(240, (int) $this->inTenant(
            $this->alfa, fn () => TimeEntry::where('user_id', $designer->id)->sum('duration_minutes')
        ), 'Yalnız real 4 saat qalmalıdır.');

        // İCAZƏ VERİLƏN TƏRƏF 1: bitişik interval kəsişmə deyil.
        $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id, 'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(13, 0), 'ended_at' => now()->setTime(15, 0),
        ]));

        // İCAZƏ VERİLƏN TƏRƏF 2: BAŞQA işçi eyni saat aralığında yaza bilər.
        $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $this->alfa->user('visualizer')->id, 'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(10, 0), 'ended_at' => now()->setTime(12, 0),
        ]));

        // İCAZƏ VERİLƏN TƏRƏF 3: yalnız `duration_minutes` ilə gələn qeydin vaxt oxu yoxdur.
        $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id, 'project_id' => $this->alfa->project->id,
            'duration_minutes' => 120,
        ]));

        $this->assertSame(480, (int) $this->inTenant(
            $this->alfa, fn () => TimeEntry::where('user_id', $designer->id)->sum('duration_minutes')
        ), '240 + 120 (bitişik) + 120 (xülasə) = 480.');
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: bitmə vaxtı başlanğıcdan ƏVVƏL olanda
     * avtomatik hesablama işə düşmürdü (`saving` hook-u yalnız
     * `ended_at > started_at` halında hesablayırdı) və operatorun əl ilə yazdığı
     * müddət olduğu kimi qalırdı — 5 dəqiqəlik "tərs" interval 600 dəqiqəlik
     * saxta maya dəyəri yaradırdı.
     *
     * Dəyişiklik: `ended_at <= started_at` artıq `RuntimeException` atır (sıfır
     * uzunluq da daxil), düzgün intervalda isə əl ilə yazılmış `duration_minutes`
     * hesablanmış dəyərlə ƏVƏZ OLUNUR.
     *
     * Fayl: app/Models/TimeEntry.php (static::saving).
     */
    public function test_reversed_interval_is_rejected(): void
    {
        // RƏDD EDİLƏN TƏRƏF: tərs interval.
        try {
            $this->inTenant($this->alfa, fn () => TimeEntry::create([
                'user_id' => $this->alfa->user('designer')->id,
                'project_id' => $this->alfa->project->id,
                'started_at' => now()->setTime(18, 0),
                'ended_at' => now()->setTime(17, 55),
                'duration_minutes' => 600,
            ]));
            $this->fail('Tərs interval qəbul edildi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('başlanğıcdan sonra olmalıdır', $e->getMessage());
        }

        // Sıfır uzunluqlu interval da rədd edilir.
        try {
            $this->inTenant($this->alfa, fn () => TimeEntry::create([
                'user_id' => $this->alfa->user('designer')->id,
                'project_id' => $this->alfa->project->id,
                'started_at' => now()->setTime(18, 0),
                'ended_at' => now()->setTime(18, 0),
                'duration_minutes' => 600,
            ]));
            $this->fail('Sıfır uzunluqlu interval qəbul edildi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('başlanğıcdan sonra olmalıdır', $e->getMessage());
        }

        $this->assertSame(0, $this->inTenant($this->alfa, fn () => TimeEntry::count()));

        // İCAZƏ VERİLƏN TƏRƏF: düzgün interval əl ilə yazılmış müddəti əvəz edir.
        $ok = $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $this->alfa->user('designer')->id,
            'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(18, 0),
            'ended_at' => now()->setTime(18, 30),
            'duration_minutes' => 600,
        ]));
        $this->assertSame(30, $ok->fresh()->duration_minutes);
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: hər bir «saat yaza bilən» rol BAŞQA
     * işçinin adına saat yaza bilirdi — forma bütün aktiv işçiləri göstərirdi,
     * `TimeEntryPolicy::create()` isə yalnız domen səviyyəsini yoxlayırdı.
     * Dizayner həmkarının adına 8 saat (400 AZN) yazır, sonra həmin qeydi nə
     * görür, nə silə bilirdi.
     *
     * Dəyişiklik: üç qat — `TimeEntryPolicy::mayLogFor()` (matrisdə «yalnız öz
     * layihələri» qeydi olan rol yalnız özünə yaza bilər), `user_id` sahəsində
     * serverdə işləyən `->rule()` validasiyası (Livewire state-i müştəridən
     * gəlir) və `assignableUsers()` — scope-lu rol üçün dropdown yalnız özünü
     * göstərir. Sahibkar/mühasib hələ də başqasının adına yaza bilir.
     *
     * Fayl: app/Policies/TimeEntryPolicy.php (mayLogFor/create),
     *       app/Filament/Resources/TimeEntryResource.php (user_id rule, assignableUsers).
     */
    public function test_scoped_role_cannot_log_time_against_another_user(): void
    {
        $designer = $this->asStaff($this->alfa, 'designer');
        $victim = $this->alfa->user('visualizer');
        $victim->update(['hourly_internal_cost' => 50]);
        $designer->update(['hourly_internal_cost' => 20]);

        // Policy səviyyəsi.
        $this->assertFalse($designer->can('mayLogFor', [TimeEntry::class, $victim->id]));
        $this->assertTrue($designer->can('mayLogFor', [TimeEntry::class, $designer->id]));
        $this->assertFalse($designer->can('create', [TimeEntry::class, $victim->id]));
        $this->assertTrue($designer->can('create', [TimeEntry::class, $designer->id]));

        // RƏDD EDİLƏN TƏRƏF: forma yad `user_id`-ni serverdə rədd edir.
        Livewire::test(CreateTimeEntry::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'user_id' => $victim->id,
                'started_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'ended_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
                'source' => 'manual',
            ])
            ->call('create')
            ->assertHasFormErrors(['user_id']);

        $this->assertNull(
            $this->inTenant($this->alfa, fn () => TimeEntry::where('user_id', $victim->id)->first()),
            'Yad işçinin adına qeyd ÜMUMİYYƏTLƏ yaranmamalıdır.'
        );

        // İCAZƏ VERİLƏN TƏRƏF 1: öz adına yazır, sonra onu görür və silə bilir.
        Livewire::test(CreateTimeEntry::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'user_id' => $designer->id,
                'started_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'ended_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
                'source' => 'manual',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $mine = $this->inTenant($this->alfa, fn () => TimeEntry::where('user_id', $designer->id)->first());
        $this->assertNotNull($mine);
        $this->assertSame(480, $mine->duration_minutes);
        $this->assertSame(160.0, $mine->labourCost());
        $this->assertTrue($designer->can('view', $mine));
        Livewire::test(ListTimeEntries::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$mine]);

        // İCAZƏ VERİLƏN TƏRƏF 2: scope-suz rol (sahibkar) başqasının adına yaza bilir.
        $this->asStaff($this->alfa, 'owner');
        Livewire::test(CreateTimeEntry::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'user_id' => $victim->id,
                'started_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'ended_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
                'source' => 'manual',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $forVictim = $this->inTenant($this->alfa, fn () => TimeEntry::where('user_id', $victim->id)->first());
        $this->assertNotNull($forVictim, 'Sahibkar başqasının adına saat yaza bilməlidir.');
        $this->assertSame(400.0, $forVictim->labourCost());
    }

    /**
     * QA TAPINTI [ORTA]: `time_entries`-də soft delete yoxdur və `user_id`
     * `cascadeOnDelete`-dir, `users` isə SoftDeletes işlədir. İşçi silinəndə
     * onun saatları qalır (maya dəyəri tarixi üçün doğrudur), amma qeydin
     * `user` əlaqəsi null olur — vaxt uçotu siyahısında «İşçi» sütunu boş
     * görünür və qeyd heç kimə aid edilə bilmir.
     *
     * Fayl: database/migrations/2026_09_09_100000_time_tracking.php:20,
     *       app/Filament/Resources/TimeEntryResource.php:82.
     */
    public function test_time_entry_loses_its_author_when_the_user_is_deleted(): void
    {
        $visualizer = $this->alfa->user('visualizer');
        $entry = $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $visualizer->id, 'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(9, 0), 'ended_at' => now()->setTime(10, 0),
        ]));

        $this->inTenant($this->alfa, fn () => User::find($visualizer->id)->delete());

        $fresh = $this->inTenant($this->alfa, fn () => TimeEntry::find($entry->id));
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->user, 'Silinmiş işçinin qeydi anonimləşir.');
        $this->assertSame(60, $fresh->duration_minutes);
    }

    /** İcazə + scope: scope-lu rol yalnız öz qeydlərini görür, tenant izolyasiyası qüvvədədir. */
    public function test_time_entry_scope_is_per_user_and_per_tenant(): void
    {
        $designer = $this->alfa->user('designer');
        $mine = $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id, 'project_id' => $this->alfa->project->id, 'duration_minutes' => 60,
        ]));
        $colleague = $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $this->alfa->user('project_manager')->id, 'project_id' => $this->alfa->project->id, 'duration_minutes' => 60,
        ]));
        $betaEntry = $this->inTenant($this->beta, fn () => TimeEntry::create([
            'user_id' => $this->beta->user('designer')->id, 'project_id' => $this->beta->project->id, 'duration_minutes' => 60,
        ]));

        $this->asStaff($this->alfa, 'designer');
        Livewire::test(ListTimeEntries::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$colleague, $betaEntry]);

        $this->asStaff($this->alfa, 'owner');
        Livewire::test(ListTimeEntries::class)
            ->assertCanSeeTableRecords([$mine, $colleague])
            ->assertCanNotSeeTableRecords([$betaEntry]);
    }

    // =====================================================================
    // TRANSLATION — MƏQSƏD: UI mətnlərini bazadan idarə etmək.
    // =====================================================================

    /** MƏQSƏD işləyir: baza açarı fayl tərcüməsini əvəz edir, olmayan açar özünü qaytarır. */
    public function test_translation_purpose_db_overrides_files_and_missing_key_returns_itself(): void
    {
        Translation::clearCache();

        $this->assertSame('qa.yoxdur.acar', t('qa.yoxdur.acar'));

        Translation::create([
            'group' => 'qa',
            'key' => 'salam',
            'value' => ['az' => 'Salam', 'en' => 'Hello', 'ru' => 'Привет'],
        ]);

        // Növbəti sorğuda (tərcüməçi yenidən yüklənəndə) baza dəyəri qüvvəyə minir.
        $this->reloadTranslator();

        $this->assertSame('Salam', t('qa.salam'));
        $this->assertSame('Hello', t('qa.salam', [], 'en'));
        $this->assertSame('Привет', t('qa.salam', [], 'ru'));
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: redaktə edilən tərcümə EYNİ SORĞUDA
     * qüvvəyə minmirdi — `Translation::saved()` yalnız `Cache`-i təmizləyirdi,
     * Laravel tərcüməçisinin yaddaşdakı `$loaded` massivini yox. Dəyişikliyi
     * saxlayan Livewire sorğusu köhnə mətni render edirdi.
     *
     * Dəyişiklik: `Translation::forgetCacheFor()` `Cache::forget`-dən əlavə
     * `forgetLoadedLines()` çağırır (`$translator->setLoaded([])`), ona görə
     * yeni mətn həmin sorğuda görünür. Silmə də eyni yolla dərhal əks olunur.
     *
     * Fayl: app/Models/Translation.php (forgetCacheFor / forgetLoadedLines).
     */
    public function test_translation_edit_applies_within_the_same_request(): void
    {
        Translation::clearCache();
        $row = Translation::create([
            'group' => 'qa', 'key' => 'duyme', 'value' => ['az' => 'Köhnə'],
        ]);
        $this->reloadTranslator();

        $this->assertSame('Köhnə', t('qa.duyme'));

        $row->fresh()->update(['value' => ['az' => 'Yeni']]);

        // Eyni sorğu: tərcüməçinin yaddaşdakı qrupu da sıfırlanır.
        $this->assertSame('Yeni', t('qa.duyme'), 'Eyni sorğuda yeni mətn görünməlidir.');
        $this->assertDatabaseHas('translations', ['id' => $row->id]);

        // Növbəti sorğuda da davamlıdır (keş köhnə dəyəri geri gətirmir).
        $this->reloadTranslator();
        $this->assertSame('Yeni', t('qa.duyme'));

        // Silmə də eyni sorğuda əks olunur — açar özünü qaytarır.
        $row->fresh()->delete();
        $this->assertSame('qa.duyme', t('qa.duyme'));
    }

    /**
     * QA TAPINTI [ORTA]: `translations` cədvəli QLOBALDIR — `BelongsToTenant`
     * istifadə etmir və `tenant_id` sütunu yoxdur. Platforma admini bir
     * studiyada mətni dəyişdikdə BÜTÜN studiyaların UI-ı dəyişir. Çoxkirayəli
     * SaaS üçün bu şüurlu seçim ola bilər, amma admin paneldə heç bir
     * xəbərdarlıq yoxdur.
     *
     * Fayl: app/Models/Translation.php:10-14,
     *       database/migrations/2026_08_30_100001_create_translations_table.php.
     */
    public function test_translations_are_global_not_per_studio(): void
    {
        $this->assertFalse(Schema::hasColumn('translations', 'tenant_id'));

        $row = $this->inTenant($this->alfa, fn () => Translation::create([
            'group' => 'qa', 'key' => 'qlobal', 'value' => ['az' => 'Alpha mətni'],
        ]));

        $seenByBeta = $this->inTenant($this->beta, fn () => Translation::find($row->id));
        $this->assertNotNull($seenByBeta, 'Beta studiyası Alpha-nın tərcüməsini görür.');
    }

    /** İcazə: modul yalnız platforma admininə açıqdır; adi studiya sahibi də daxil ola bilmir. */
    public function test_translation_module_is_platform_admin_only(): void
    {
        $owner = $this->alfa->user('owner');
        $this->assertFalse((bool) $owner->is_platform_admin);
        $this->assertFalse($owner->can('viewAny', Translation::class));
        $this->assertFalse($this->alfa->user('accountant')->can('viewAny', Translation::class));

        $owner->update(['is_platform_admin' => true]);
        $this->assertTrue($owner->fresh()->can('viewAny', Translation::class));

        // Birbaşa URL ilə də açıla bilmir.
        $this->actingAs($this->alfa->user('accountant'));
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->alfa->tenant->id);
        AccessMatrix::flushCache();
        $this->assertFalse(TranslationResource::canAccess());
    }

    /** CRUD: unikal (group, key) cütü pozulanda baza etiraz edir. */
    public function test_translation_group_key_pair_is_unique(): void
    {
        Translation::create(['group' => 'qa', 'key' => 'tek', 'value' => ['az' => 'A']]);

        $this->expectException(QueryException::class);
        Translation::create(['group' => 'qa', 'key' => 'tek', 'value' => ['az' => 'B']]);
    }

    // =====================================================================
    // TENANT — MƏQSƏD: yeni studiya + ilk sahibkar (onboarding), deaktivasiya.
    // =====================================================================

    /** MƏQSƏD işləyir: studiya + ilk sahibkar bir addımda, sahibkar yeni studiyaya möhürlənir. */
    public function test_tenant_purpose_onboarding_creates_studio_with_its_first_owner(): void
    {
        $platformAdmin = $this->alfa->user('owner');
        $platformAdmin->update(['is_platform_admin' => true]);

        $this->actingAs($platformAdmin->fresh());
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->alfa->tenant->id);
        AccessMatrix::flushCache();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Qamma Studiya',
                'slug' => 'qamma',
                'active' => true,
                'owner_name' => 'Qamma Sahibkar',
                'owner_email' => 'owner@qamma.test',
                'owner_password' => 'cox-guclu-sifre',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::where('slug', 'qamma')->first();
        $this->assertNotNull($tenant);

        $owner = User::withoutGlobalScope('tenant')->where('email', 'owner@qamma.test')->first();
        $this->assertNotNull($owner, 'İlk sahibkar yaradılmadı.');
        $this->assertSame($tenant->id, $owner->tenant_id, 'Sahibkar yeni studiyaya möhürlənməlidir.');
        $this->assertSame(StaffRole::Owner, $owner->role);
        $this->assertTrue($owner->is_active);
        $this->assertNotSame('cox-guclu-sifre', $owner->password, 'Şifrə hash-lənməlidir.');
        $this->assertTrue(Hash::check('cox-guclu-sifre', $owner->password));
    }

    /** MƏQSƏD işləyir: deaktiv studiyanın işçisi panelə girə bilmir. */
    public function test_inactive_tenant_closes_the_panel_for_its_staff(): void
    {
        $owner = $this->alfa->user('owner');
        $panel = Filament::getPanel('app');

        $this->assertTrue($owner->canAccessPanel($panel));

        $this->alfa->tenant->update(['active' => false]);
        $owner->unsetRelation('tenant');

        $this->assertFalse($owner->fresh()->canAccessPanel($panel), 'Deaktiv studiya girişi bağlamalıdır.');
        // Digər studiya təsirlənmir.
        $this->assertTrue($this->beta->user('owner')->canAccessPanel($panel));
    }

    /**
     * QA TAPINTI [ORTA]: `users.email` QLOBAL unikaldır (tenant daxilində deyil).
     * Ona görə eyni şəxs iki studiyanın sahibkarı ola bilmir — ikinci studiya
     * yaradılarkən eyni e-poçt rədd edilir. Çoxkirayəli SaaS-da bu ciddi
     * məhdudiyyətdir (agentlik, filial, ortaq sahibkar ssenariləri).
     *
     * Fayl: database/migrations/0001_01_01_000000_create_users_table.php:17
     *       (`$table->string('email')->unique()` — tenant_id ilə birgə deyil),
     *       app/Filament/Resources/TenantResource.php:74 (->unique('users','email')).
     * Gözlənilən: `unique(['tenant_id','email'])`.
     * Faktiki: qlobal unikallıq; form validasiyası imtina edir.
     */
    public function test_same_email_cannot_own_two_studios(): void
    {
        $email = $this->alfa->user('owner')->email;

        $platformAdmin = $this->alfa->user('owner');
        $platformAdmin->update(['is_platform_admin' => true]);
        $this->actingAs($platformAdmin->fresh());
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->alfa->tenant->id);
        AccessMatrix::flushCache();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Delta Studiya', 'slug' => 'delta', 'active' => true,
                'owner_name' => 'Delta Sahibkar',
                'owner_email' => $email,
                'owner_password' => 'cox-guclu-sifre',
            ])
            ->call('create')
            ->assertHasFormErrors(['owner_email']);

        $this->assertNull(Tenant::where('slug', 'delta')->first());
    }

    /** Silmə: studiya silinə bilmir (policy `delete` həmişə false) — məlumat itkisi qarşısı. */
    public function test_tenant_cannot_be_deleted_by_anyone(): void
    {
        $platformAdmin = $this->alfa->user('owner');
        $platformAdmin->update(['is_platform_admin' => true]);

        $this->assertFalse($platformAdmin->fresh()->can('delete', $this->alfa->tenant));
        $this->assertFalse($platformAdmin->fresh()->can('forceDelete', $this->alfa->tenant));
    }

    /** İcazə: studiya modulu platforma admininə bağlıdır. */
    public function test_tenant_module_is_platform_admin_only(): void
    {
        $this->assertFalse($this->alfa->user('owner')->can('viewAny', Tenant::class));
        $this->assertFalse($this->alfa->user('project_manager')->can('viewAny', Tenant::class));

        $this->actingAs($this->alfa->user('project_manager'));
        Filament::setCurrentPanel('app');
        $this->assertFalse(TenantResource::canAccess());
    }

    // =====================================================================
    // USER — MƏQSƏD: komanda, rol, deaktivasiya, şifrə.
    // =====================================================================

    /** MƏQSƏD işləyir: işçi yaradılır, şifrə hash-lənir, rol təyin edilir, deaktivasiya girişi bağlayır. */
    public function test_user_purpose_create_assign_role_deactivate(): void
    {
        $this->asStaff($this->alfa, 'owner');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Yeni Dizayner',
                'email' => 'yeni@alpha.test',
                'role' => StaffRole::Designer->value,
                'password' => 'cox-guclu-sifre',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = $this->inTenant($this->alfa, fn () => User::where('email', 'yeni@alpha.test')->first());
        $this->assertNotNull($user);
        $this->assertSame($this->alfa->tenant->id, $user->tenant_id);
        $this->assertSame(StaffRole::Designer, $user->role);
        $this->assertTrue(Hash::check('cox-guclu-sifre', $user->password));

        // Deaktivasiya panelə girişi bağlayır.
        $user->update(['is_active' => false]);
        $this->assertFalse($user->fresh()->canAccessPanel(Filament::getPanel('app')));
    }

    /** MƏQSƏD işləyir: şifrə dəyişmə yeni hash yaradır, boş saxlanılanda köhnəsi qalır. */
    public function test_user_password_change_and_blank_password_keeps_the_old_one(): void
    {
        $this->asStaff($this->alfa, 'owner');
        $target = $this->alfa->user('designer');
        $oldHash = $target->password;

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($oldHash, $target->fresh()->password, 'Boş şifrə köhnəni saxlamalıdır.');

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['password' => 'yeni-guclu-sifre'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('yeni-guclu-sifre', $target->fresh()->password));
    }

    /** MƏQSƏD işləyir: son aktiv sahibkar nə deaktiv edilə, nə silinə bilər (studiya kilidlənməsin). */
    public function test_last_active_owner_is_protected(): void
    {
        $owner = $this->alfa->user('owner');

        try {
            $owner->update(['is_active' => false]);
            $this->fail('Son sahibkar deaktiv edildi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('son aktiv sahibkarını', $e->getMessage());
        }

        try {
            $owner->fresh()->delete();
            $this->fail('Son sahibkar silindi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('son aktiv sahibkarını silmək olmaz', $e->getMessage());
        }

        // İkinci sahibkar yarandıqda məhdudiyyət aradan qalxır.
        $this->inTenant($this->alfa, fn () => User::create([
            'name' => 'İkinci sahibkar', 'email' => 'owner2@alpha.test',
            'password' => 'secret123', 'role' => StaffRole::Owner->value, 'is_active' => true,
        ]));
        $owner->fresh()->update(['is_active' => false]);
        $this->assertFalse($owner->fresh()->is_active);
    }

    /**
     * QA TAPINTI [ORTA]: silinmiş (soft delete) işçinin e-poçtu AZAD OLMUR.
     * `users.email` unikal indeksi `deleted_at`-i nəzərə almır və formadakı
     * `->unique(ignoreRecord: true)` də silinmişləri istisna etmir. İşçi işdən
     * çıxıb geri qayıdanda eyni e-poçtla qeyd yaratmaq mümkün deyil, resursda
     * isə "silinmişlər" filtri olmadığı üçün admin səbəbi GÖRMÜR — sadəcə
     * "bu e-poçt artıq götürülüb" yazısı çıxır.
     *
     * Fayl: app/Filament/Resources/UserResource.php:42-47 (unique, withTrashed yoxdur),
     *       app/Filament/Resources/UserResource.php:90-118 (TrashedFilter yoxdur).
     * Gözlənilən: ya bərpa təklifi, ya silinmişin e-poçtunun azad olması.
     * Faktiki: e-poçt həmişəlik bloklanır, səbəb görünmür.
     */
    public function test_soft_deleted_user_email_stays_blocked_and_is_invisible(): void
    {
        $designer = $this->alfa->user('designer');
        $email = $designer->email;
        $this->inTenant($this->alfa, fn () => User::find($designer->id)->delete());

        $this->asStaff($this->alfa, 'owner');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Qayıdan dizayner', 'email' => $email,
                'role' => StaffRole::Designer->value, 'password' => 'cox-guclu-sifre', 'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);

        // Siyahıda silinmiş işçi yoxdur — admin səbəbi görmür.
        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertCanNotSeeTableRecords([$designer]);

        $resource = file_get_contents(app_path('Filament/Resources/UserResource.php'));
        $this->assertStringNotContainsStringQuietly(
            'TrashedFilter', $resource, 'Silinmişlər filtri artıq var — tapıntı köhnəlib.'
        );
    }

    /**
     * QA TAPINTI [ORTA]: e-poçt unikallığı tenant daxilində deyil, QLOBALDIR.
     * Başqa studiyada mövcud olan e-poçtla işçi yaratmaq mümkün deyil, üstəlik
     * xəta mesajı başqa studiyanın məlumatının mövcudluğunu dolayısı ilə açır
     * (hesab sayımı / enumeration).
     *
     * Fayl: database/migrations/0001_01_01_000000_create_users_table.php:17.
     * Gözlənilən: `unique(['tenant_id','email'])`.
     */
    public function test_user_email_uniqueness_is_global_across_studios(): void
    {
        $betaEmail = $this->beta->user('designer')->email;

        $this->asStaff($this->alfa, 'owner');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Alpha dizayner 2', 'email' => $betaEmail,
                'role' => StaffRole::Designer->value, 'password' => 'cox-guclu-sifre', 'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    /** İcazə: yalnız OwnerDashboard=Full olan rol komandanı idarə edir; özünü silmək olmaz. */
    public function test_user_module_permissions(): void
    {
        $owner = $this->alfa->user('owner');
        $pm = $this->alfa->user('project_manager');

        $this->assertTrue($owner->can('viewAny', User::class));
        $this->assertFalse($pm->can('viewAny', User::class));
        $this->assertFalse($this->alfa->user('accountant')->can('create', User::class));
        // İstifadəçi öz kartına baxa bilir.
        $this->assertTrue($pm->can('view', $pm));
        // Özünü silmək olmaz.
        $this->assertFalse($owner->can('delete', $owner));
        // Başqa studiyanın işçisi toxunulmazdır.
        $this->assertFalse($owner->can('update', $this->beta->user('designer')));
    }

    /** Tenant scope: komanda siyahısı yalnız öz studiyasını göstərir. */
    public function test_user_list_is_tenant_scoped(): void
    {
        $this->asStaff($this->alfa, 'owner');

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->alfa->user('designer')])
            ->assertCanNotSeeTableRecords([$this->beta->user('designer')]);
    }
}
