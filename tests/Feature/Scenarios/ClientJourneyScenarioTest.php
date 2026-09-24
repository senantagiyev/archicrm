<?php

namespace Tests\Feature\Scenarios;

use App\Enums\AccessLevel;
use App\Enums\ApprovalStatus;
use App\Enums\Domain;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SpecificationCategory;
use App\Enums\SpecificationStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\ApprovalResource;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ProjectResource\Pages\CreateProject;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\SpecificationsRelationManager;
use App\Filament\Resources\TaskResource\Pages\CreateTask;
use App\Models\Brief;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Expense;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SpecificationItem;
use App\Models\StageTemplate;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Brief\BriefRiskDetector;
use App\Services\Brief\BriefService;
use App\Services\Chat\ChatService;
use App\Services\Finance\ProfitabilityService;
use App\Services\Stages\StageTemplateService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\BriefQuestionBankSeeder;
use Database\Seeders\StageTemplateSeeder;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bir layihənin BÜTÜN ömrü — bir hekayə kimi.
 *
 * Qalan ssenari testləri hər modulu ayrıca yoxlayır; burada isə modulların
 * BİR-BİRİNƏ bağlandığı yerlər sınanır, çünki istehsalatda sıradan çıxan məhz
 * o tikişlərdir: brif cavabı texniki tapşırığa düşürmü, mərhələ şablonu
 * hazırlıq faizini doğru çəkirmi, ödəniş qalıq borcu yenidən hesablayırmı,
 * razılaşdırılmış smeta rentabellik hesabatına eyni rəqəmlə gedirmi.
 *
 * Hekayə: studiya müştəri açır → layihə yaradır → müştəri portaldan brifi
 * doldurub göndərir → texniki tapşırıq qurulur → mərhələ şablonu tətbiq olunur
 * → tapşırıq təyin edilib bitirilir → ödəniş alınır → xərc və saatlar yazılır →
 * rentabellik hesabatı oxunur.
 */
class ClientJourneyScenarioTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $manager;

    private User $designer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TranslationSeeder::class);
        $this->seed(BriefQuestionBankSeeder::class);
        $this->seed(StageTemplateSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Jurney Studio', 'slug' => 'journey', 'active' => true]);

        app(TenantContext::class)->actingAs($this->tenant->id, function (): void {
            $this->owner = User::create([
                'name' => 'Sahibkar', 'email' => 'owner@journey.test',
                'password' => 'secret123', 'role' => 'owner', 'is_active' => true,
                'hourly_internal_cost' => 40,
            ]);

            $this->manager = User::create([
                'name' => 'Menecer', 'email' => 'pm@journey.test',
                'password' => 'secret123', 'role' => 'project_manager', 'is_active' => true,
                'hourly_internal_cost' => 30,
            ]);

            $this->designer = User::create([
                'name' => 'Dizayner', 'email' => 'designer@journey.test',
                'password' => 'secret123', 'role' => 'designer', 'is_active' => true,
                'hourly_internal_cost' => 25,
            ]);
        });
    }

    /**
     * Hekayənin özü. Bir metod içində saxlanılır, çünki hər addım əvvəlkinin
     * bazada qoyduğu izə söykənir — ayrılsaydılar, zəncirin qırıldığı yer
     * görünməzdi.
     */
    public function test_the_whole_project_lifecycle_holds_together(): void
    {
        $this->actingAs($this->owner);
        app(TenantContext::class)->set($this->tenant->id);

        // ── 1. Müştəri paneldən yaradılır ────────────────────────────────────
        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Aysel Məmmədova',
                'status' => 'client',
                'phone' => '+994501234567',
                'email' => 'aysel@example.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::where('name', 'Aysel Məmmədova')->firstOrFail();
        $this->assertSame($this->tenant->id, $client->tenant_id, 'Müştəri cari studiyaya bağlanmadı.');

        // ── 2. Layihə paneldən yaradılır ─────────────────────────────────────
        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => $client->id,
                'name' => 'Aysel — 3 otaqlı mənzil',
                'type' => 'apartment',
                'status' => 'active',
                'manager_user_id' => $this->manager->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::where('name', 'Aysel — 3 otaqlı mənzil')->firstOrFail();
        $this->assertSame($this->tenant->id, $project->tenant_id);

        // ── 3. Müştəriyə portal hesabı açılır ────────────────────────────────
        $portalUser = ClientUser::create([
            'client_id' => $client->id,
            'name' => 'Aysel Məmmədova',
            'email' => 'aysel@example.test',
        ]);

        // ── 4. Brif: müştəri portaldan doldurur ──────────────────────────────
        $brief = app(BriefService::class)->forProject($project);
        app(BriefService::class)->switchTemplate($brief, BriefTemplate::where('key', 'quick')->firstOrFail());
        $brief->refresh();

        $section = BriefSection::where('key', 'quick_start')->firstOrFail();

        $this->actingAs($portalUser, 'customer')
            ->get(route('portal.brief', $project))
            ->assertOk();

        foreach ($this->quickAnswers() as $key => $value) {
            $question = BriefQuestion::where('brief_section_id', $section->id)->where('key', $key)->firstOrFail();

            $this->actingAs($portalUser, 'customer')
                ->patch(route('portal.brief.autosave', [$project, $section]), [
                    'question_id' => $question->id,
                    'value' => $value,
                    'delegated' => false,
                ])
                ->assertOk();
        }

        // Bölmə göndərilir: məcburi sual qalıbsa, controller geri qaytarır.
        $this->actingAs($portalUser, 'customer')
            ->post(route('portal.brief.submit', [$project, $section]))
            ->assertSessionHasNoErrors();

        // Brifin özü göndərilir.
        $this->actingAs($portalUser, 'customer')
            ->post(route('portal.brief.send', $project));

        $brief->refresh();
        $this->assertTrue($brief->isLocked(), 'Göndərilmiş brif kilidlənmədi — müştəri cavabları hələ dəyişə bilər.');

        // Cavablar həqiqətən yazılıb: ünvan sualı geri oxunur.
        $values = app(BriefService::class)->valuesByKey($brief->fresh());
        $this->assertSame('Bakı, Nizami küç. 12', $values['object_address'] ?? null);
        $this->assertSame('85', (string) ($values['total_area_sqm'] ?? null));

        // ── 5. Texniki tapşırıq brif cavablarından qurulur ───────────────────
        $spec = app(BriefService::class)->buildTechnicalSpec($brief->fresh());

        $this->assertSame($project->id, $spec->project_id);
        $this->assertSame(1, (int) $brief->fresh()->technical_spec_version, 'İlk texniki tapşırıq 1-ci versiya olmalıdır.');

        // ── 6. Mərhələ şablonu tətbiq olunur ─────────────────────────────────
        $template = StageTemplate::where('key', 'design_project')->firstOrFail();
        app(StageTemplateService::class)->apply($project, $template);

        $stages = $project->fresh()->stages()->orderBy('position')->get();
        $this->assertGreaterThan(1, $stages->count(), 'Şablon mərhələ yaratmadı.');
        $this->assertTrue(
            $stages->every(fn ($stage) => (int) $stage->weight >= 1),
            'Sıfır çəkili mərhələ var — hazırlıq faizinə heç nə vermir.',
        );

        // ── 7. Tapşırıq paneldən yaradılıb dizaynerə təyin edilir ────────────
        $firstStage = $stages->first();

        $this->actingAs($this->manager);

        Livewire::test(CreateTask::class)
            ->fillForm([
                'project_id' => $project->id,
                'stage_id' => $firstStage->id,
                'title' => 'Ölçmə və planlaşdırma',
                'status' => TaskStatus::Todo->value,
                'assignee_user_id' => $this->designer->id,
                // Forma icraçını və son tarixi məcburi sayır — ikisi də
                // gecikmə hesabatının daşıyıcısıdır.
                'deadline' => now()->addWeek()->toDateString(),
                'priority' => 'normal',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = Task::where('title', 'Ölçmə və planlaşdırma')->firstOrFail();
        $this->assertSame($this->designer->id, $task->assignee_user_id);

        $readinessBefore = (float) $project->fresh()->readiness;

        $task->update(['status' => TaskStatus::Done->value]);

        $this->assertGreaterThan(
            $readinessBefore,
            (float) $project->fresh()->readiness,
            'Tapşırıq bitdi, amma layihənin hazırlıq faizi tərpənmədi — observer zənciri qırılıb.',
        );

        // ── 8. Smeta sətri razılaşdırılır → qalıq borc yaranır ───────────────
        $line = $project->budgetLines()->create([
            'work_type' => 'Dizayn-layihə', 'unit' => 'm2', 'qty' => 85,
            'work_price' => 20, 'material_price' => 0, 'position' => 1,
            'visible_to_client' => true,
        ]);

        $line->forceFill(['approval_status' => ApprovalStatus::Approved->value])->save();

        $this->assertSame(
            1700.0,
            round((float) $project->fresh()->debt, 2),
            '85 m² × 20 ₼ = 1700 ₼ razılaşdırıldı; qalıq borc bu rəqəmi göstərməlidir.',
        );

        // ── 9. Ödəniş: 700 ₼ alınır ──────────────────────────────────────────
        $project->payments()->create([
            'title' => 'Avans', 'amount' => 700,
            'status' => PaymentStatus::Paid->value, 'paid_at' => now(),
        ]);

        $this->assertSame(
            1000.0,
            round((float) $project->fresh()->debt, 2),
            '1700 − 700 = 1000 ₼ qalıq borc gözlənilirdi.',
        );

        // ── 10. Xərc və saatlar → rentabellik ────────────────────────────────
        Expense::create([
            'project_id' => $project->id,
            'category' => 'transport',
            'vendor' => 'Taksi',
            'amount' => 120,
            'currency' => 'AZN',
            'date' => now(),
            'status' => ExpenseStatus::Approved->value,
            'created_by_user_id' => $this->manager->id,
        ]);

        TimeEntry::create([
            'user_id' => $this->designer->id,
            'project_id' => $project->id,
            'duration_minutes' => 600,          // 10 saat
            'hourly_cost_snapshot' => 25,       // 250 ₼
            'comment' => 'Planlaşdırma',
        ]);

        $report = app(ProfitabilityService::class)->forProject($project->fresh());

        // Gəlir yalnız ÖDƏNİLMİŞ ödənişlərdir (700), maya dəyəri 250 + 120 = 370.
        $this->assertSame(700.0, $report['revenue']);
        $this->assertSame(250.0, $report['labor_cost']);
        $this->assertSame(120.0, $report['expenses']);
        $this->assertSame(370.0, $report['cost']);
        $this->assertSame(330.0, $report['gross_profit']);
        $this->assertSame(47.1, $report['margin'], '330 / 700 = 47.14…% — bir onluq dəqiqliklə.');

        // ── 11. Müştəri portalda eyni rəqəmləri görür ────────────────────────
        $this->actingAs($portalUser, 'customer')
            ->get(route('portal.payments', $project))
            ->assertOk()
            // Portal minliyi nazik boşluqla ayırır: «1 000.00 ₼».
            ->assertSee(number_format(1000, 2, '.', ' '));
    }

    /**
     * Rentabellik gəliri yalnız ödənilmiş ödənişdən götürür. Gözləyən ödəniş
     * hesabatda gəlir kimi görünsəydi, studiya hələ almadığı pula mənfəət
     * yazardı.
     */
    public function test_a_pending_payment_is_not_revenue(): void
    {
        $this->actingAs($this->owner);
        app(TenantContext::class)->set($this->tenant->id);

        $project = $this->bareProject();

        $project->payments()->create([
            'title' => 'Gözləyən', 'amount' => 900,
            'status' => PaymentStatus::Pending->value, 'due_date' => now()->addWeek(),
        ]);

        $report = app(ProfitabilityService::class)->forProject($project->fresh());

        $this->assertSame(0.0, $report['revenue'], 'Gözləyən ödəniş gəlir sayıldı.');
        $this->assertNull($report['margin'], 'Gəlir yoxdursa marja 0% deyil, «—» olmalıdır.');
    }

    /**
     * Razılaşdırılmamış smeta borc deyil: müştəri hələ heç nəyə «hə» deməyib.
     */
    public function test_an_unapproved_estimate_line_creates_no_debt(): void
    {
        $this->actingAs($this->owner);
        app(TenantContext::class)->set($this->tenant->id);

        $project = $this->bareProject();

        $project->budgetLines()->create([
            'work_type' => 'Təklif', 'unit' => 'm2', 'qty' => 10,
            'work_price' => 100, 'material_price' => 0, 'position' => 1,
        ]);

        $this->assertSame(0.0, round((float) $project->fresh()->debt, 2));
    }

    /**
     * Mərhələ «Bitdi» qoyulanda tapşırıqlardan asılı olmayaraq 100% oxunmalıdır
     * — əks halda menecer mərhələni bağlayır, faiz isə yarıda qalır.
     */
    public function test_a_stage_marked_done_reads_as_complete(): void
    {
        $this->actingAs($this->owner);
        app(TenantContext::class)->set($this->tenant->id);

        $project = $this->bareProject();

        $stage = $project->stages()->create([
            'name' => 'Eskiz', 'position' => 1, 'weight' => 2, 'status' => StageStatus::InProgress->value,
        ]);

        Task::create([
            'project_id' => $project->id, 'stage_id' => $stage->id,
            'title' => 'Yarımçıq iş', 'status' => TaskStatus::Todo->value,
        ]);

        $stage->update(['status' => StageStatus::Done->value]);

        $this->assertSame(
            100.0,
            round((float) $stage->fresh()->readiness, 2),
            'Bitmiş mərhələ hələ də yarımçıq tapşırığın faizini göstərir.',
        );
    }

    /**
     * Texniki tapşırıq brifin ÖZ cavablarını daşımalıdır.
     *
     * Sənəd PDF kimi yazılır, ona görə məzmunu eyni blade şablonunu render
     * edərək yoxlayırıq: zəncir qırılsaydı (cavablar ötürülməsəydi), TT boş
     * formaya çevrilərdi və heç kim fərqinə varmazdı — fayl yenə yaranır.
     */
    public function test_the_technical_spec_carries_the_clients_own_answers(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner);
        app(TenantContext::class)->set($this->tenant->id);

        $project = $this->bareProject();
        $portalUser = ClientUser::create([
            'client_id' => $project->client_id,
            'name' => 'TT müştərisi',
            'email' => 'tz@example.test',
        ]);

        $brief = $this->fillQuickBrief($project, $portalUser);

        $spec = app(BriefService::class)->buildTechnicalSpec($brief->fresh());

        // Sənəd faktiki olaraq `public` diskində olmalıdır — panelə yüklənən
        // fayllar bir dəfə `storage/app/private`-a düşüb portalda 404 vermişdi.
        Storage::disk('public')->assertExists($spec->file_path);
        $this->assertFalse(
            (bool) $spec->visible_to_client,
            'Texniki tapşırıq razılaşdırmaya göndərilənə qədər müştəriyə görünməməlidir.',
        );

        $html = view('portal.brief.technical-spec', [
            'brief' => $brief->fresh(),
            'project' => $project->fresh(),
            'version' => 1,
            'map' => app(BriefService::class)->sectionMap($brief->fresh()),
            'answers' => $brief->answers()->with('question')->get(),
            'risks' => app(BriefRiskDetector::class)->detect($brief->fresh()),
        ])->render();

        $this->assertStringContainsString('Bakı, Nizami küç. 12', $html, 'Müştərinin yazdığı ünvan texniki tapşırığa düşmədi.');
        $this->assertStringContainsString('İş masası pəncərə önündə olsun.', $html, 'Sərbəst mətn cavabı TT-də yoxdur.');
    }

    /**
     * «Spesifikasiya» tabı bir sətir olan kimi 500 verirdi.
     *
     * Filament closure arqumentlərini ƏVVƏLCƏ ADA görə ötürür; `fn
     * (SpecificationCategory $c)` heç nəyə uyğun gəlmirdi, ona görə tipə görə
     * konteynerdən həll olunmağa çalışılır və enum `new` edilməyə cəhd
     * edilirdi. Boş layihədə tab açılırdı — qüsur yalnız məlumat olanda üzə
     * çıxırdı, yəni real işləyən layihələrdə.
     */
    public function test_the_specifications_tab_opens_when_it_has_rows(): void
    {
        $this->actingAs($this->owner);
        app(TenantContext::class)->set($this->tenant->id);

        $project = $this->bareProject();

        app(TenantContext::class)->actingAs($this->tenant->id, fn () => SpecificationItem::create([
            'project_id' => $project->id,
            'product_name' => 'Divan',
            'category' => SpecificationCategory::cases()[0]->value,
            'status' => SpecificationStatus::cases()[0]->value,
            'quantity' => 1,
            'client_price' => 1200,
        ]));

        Livewire::test(SpecificationsRelationManager::class, [
            'ownerRecord' => $project->fresh(),
            'pageClass' => EditProject::class,
        ])->assertOk();
    }

    /**
     * Razılaşdırma nişanı siyahı ilə eyni rəqəmi göstərməlidir.
     *
     * Nişan filtrsiz sayırdı: «yalnız öz layihəsi» rolu menyuda üzvü olmadığı
     * layihələrin razılaşdırmalarını da sayırdı və siyahını açanda onları
     * tapmırdı.
     */
    public function test_the_approval_badge_counts_only_what_the_list_shows(): void
    {
        app(TenantContext::class)->set($this->tenant->id);

        $mine = $this->bareProject();
        $mine->members()->attach($this->designer->id, ['project_role' => 'designer']);

        $foreign = $this->bareProject();

        app(TenantContext::class)->actingAs($this->tenant->id, function () use ($mine, $foreign): void {
            foreach ([$mine, $foreign] as $project) {
                $line = $project->budgetLines()->create([
                    'work_type' => 'İş', 'unit' => 'ədəd', 'qty' => 1,
                    'work_price' => 100, 'material_price' => 0, 'position' => 1,
                    'visible_to_client' => true,
                ]);

                app(ApprovalService::class)->request($line, $this->manager);
            }
        });

        $this->actingAs($this->designer);
        app(TenantContext::class)->set($this->tenant->id);
        AccessMatrix::flushCache();

        $this->assertSame(
            (string) ApprovalResource::getEloquentQuery()->where('status', ApprovalStatus::Pending->value)->count(),
            ApprovalResource::getNavigationBadge(),
            'Menyudakı rəqəm siyahıdakından fərqlidir.',
        );
        $this->assertSame('1', ApprovalResource::getNavigationBadge(), 'Dizayner yalnız öz layihəsinin razılaşdırmasını saymalıdır.');
    }

    /**
     * Mənfi vaxt qeydi maya dəyərini silirdi.
     *
     * Rentabellik saatları birbaşa toplayır, ona görə −600 dəqiqəlik sətir eyni
     * layihədəki +600-ü sıfırlayır: 250 ₼ əmək xərci 0 olur, marja isə 75%
     * əvəzinə 100% görünür. Forma bunu bloklayırdı, model isə yox — yəni
     * import, konsol və API yolları açıq idi.
     */
    public function test_a_negative_time_entry_is_refused_by_the_model(): void
    {
        $this->actingAs($this->owner);
        app(TenantContext::class)->set($this->tenant->id);

        $project = $this->bareProject();

        $this->expectException(\RuntimeException::class);

        TimeEntry::create([
            'user_id' => $this->designer->id,
            'project_id' => $project->id,
            'duration_minutes' => -600,
            'hourly_cost_snapshot' => 25,
        ]);
    }

    /**
     * Mənfi satınalma sifarişi maya dəyərini azaldıb layihəni olduğundan
     * gəlirli göstərirdi. `Invoice`, `Expense` və `Payment` modellərində bu
     * qoruma var idi, sifarişdə isə yox idi.
     */
    public function test_a_negative_purchase_order_is_refused_by_the_model(): void
    {
        $this->actingAs($this->owner);
        app(TenantContext::class)->set($this->tenant->id);

        $project = $this->bareProject();

        $this->expectException(\RuntimeException::class);

        PurchaseOrder::create([
            'project_id' => $project->id,
            'number' => 'PO-'.uniqid(),
            'status' => PurchaseOrderStatus::Ordered->value,
            'subtotal' => -4000,
            'tax' => 0,
        ]);
    }

    /**
     * Təyin olunmuş rolu silmək məhdudiyyəti silirdi.
     *
     * `users.role_id` FK `nullOnDelete`-dir: məhdudlaşdırıcı xüsusi rolu silmək
     * onu daşıyan işçiləri səssizcə BAZA roluna qaytarır, yəni işçi birdən-birə
     * daha çox görməyə başlayır. Silmə indi bloklanır.
     */
    public function test_a_role_still_assigned_to_someone_cannot_be_deleted(): void
    {
        app(TenantContext::class)->set($this->tenant->id);

        $role = app(TenantContext::class)->actingAs($this->tenant->id, function (): Role {
            $role = Role::create([
                'key' => 'mehdud-dizayner',
                'name' => 'Məhdud dizayner',
                'levels' => [],
                'own_projects_only' => true,
                'is_system' => false,
                'active' => true,
            ]);

            $this->designer->forceFill(['role_id' => $role->id])->save();

            return $role;
        });

        try {
            $role->delete();
            $this->fail('Təyin olunmuş rol silindi — daşıyıcıları səssizcə baza roluna qayıtdı.');
        } catch (\RuntimeException) {
            // Gözlənilən.
        }

        $this->assertNotNull(Role::find($role->id));
        $this->assertSame($role->id, $this->designer->fresh()->role_id);
    }

    /**
     * Bazadakı yad səviyyə dəyəri icazəni BAĞLAMALIDIR, sorğunu qırmamalıdır.
     * `AccessLevel::from()` belə halda ValueError atırdı.
     */
    public function test_a_corrupt_level_value_denies_instead_of_crashing(): void
    {
        $role = new Role(['levels' => [Domain::Payments->value => 7]]);

        $this->assertSame(AccessLevel::None, $role->level(Domain::Payments));
    }

    /**
     * Deaktiv edilmiş işçinin oxunmamış sayğacı susmalıdır.
     *
     * Mesajların mətni onsuz da bağlı idi, amma sayğac layihə siyahısından
     * qidalanırdı — işdən çıxmış, sessiyası açıq qalmış işçi studiyada nə qədər
     * yeni yazışma getdiyini görməyə davam edirdi.
     */
    public function test_a_deactivated_employee_stops_seeing_the_unread_counter(): void
    {
        app(TenantContext::class)->set($this->tenant->id);

        $project = $this->bareProject();
        $project->members()->attach($this->designer->id, ['project_role' => 'designer']);

        $chat = app(ChatService::class);

        $this->assertNotEmpty(
            $chat->staffProjectIds($this->designer->fresh()),
            'Aktiv işçi öz layihəsini görməlidir — testin şərti budur.',
        );

        $this->designer->forceFill(['is_active' => false])->save();
        AccessMatrix::flushCache();

        $this->assertSame([], $chat->staffProjectIds($this->designer->fresh()));
    }

    /**
     * Başqa studiyanın brifi — nə oxunur, nə də YAZILIR.
     *
     * `CrossStudioScenarioTest` layihə, tapşırıq, çat və sənədi örtür, brif isə
     * orada yoxdur; halbuki brifdə ünvan, telefon, büdcə və ailə tərkibi var —
     * sızsa, ən həssas məlumat sızır.
     */
    public function test_a_foreign_studios_brief_is_neither_readable_nor_writable(): void
    {
        $this->actingAs($this->owner);
        app(TenantContext::class)->set($this->tenant->id);

        $ourProject = $this->bareProject();
        $ourBrief = app(BriefService::class)->forProject($ourProject);
        app(BriefService::class)->switchTemplate($ourBrief, BriefTemplate::where('key', 'quick')->firstOrFail());

        // İkinci studiya və onun öz müştərisi.
        $foreignTenant = Tenant::create(['name' => 'Yad Studio', 'slug' => 'yad', 'active' => true]);

        [$foreignProject, $foreignPortalUser] = app(TenantContext::class)->actingAs($foreignTenant->id, function () use ($foreignTenant): array {
            $manager = User::create([
                'name' => 'Yad menecer', 'email' => 'pm@yad.test',
                'password' => 'secret123', 'role' => 'project_manager', 'is_active' => true,
            ]);

            $client = Client::create(['name' => 'Yad müştəri', 'status' => 'client']);

            $project = Project::create([
                'client_id' => $client->id, 'name' => 'Yad layihə',
                'type' => 'apartment', 'status' => 'active',
                'manager_user_id' => $manager->id,
            ]);

            $portalUser = ClientUser::create([
                'client_id' => $client->id, 'name' => 'Yad portal', 'email' => 'portal@yad.test',
            ]);

            // Studiyanın adı istifadə olunmasa da, tenant kontekstinin doğru
            // qurulduğunu sənədləşdirmək üçün saxlanılır.
            $this->assertSame($foreignTenant->id, $project->tenant_id);

            return [$project, $portalUser];
        });

        $section = BriefSection::where('key', 'quick_start')->firstOrFail();
        $question = BriefQuestion::where('brief_section_id', $section->id)->where('key', 'object_address')->firstOrFail();

        // Yad müştəri BİZİM brifimizi açmağa və ora cavab YAZMAĞA çalışır.
        // 403 və 404 arasındakı fərq burada əhəmiyyətsizdir — hər ikisi «yox»
        // deməkdir; əhəmiyyətli olan 200-ün olmamasıdır.
        $attempts = [
            'brif xəritəsi' => fn () => $this->get(route('portal.brief', $ourProject)),
            'bölmə səhifəsi' => fn () => $this->get(route('portal.brief.section', [$ourProject, $section])),
            'cavab yazmaq' => fn () => $this->patch(route('portal.brief.autosave', [$ourProject, $section]), [
                'question_id' => $question->id,
                'value' => 'Oğurlanmış ünvan',
                'delegated' => false,
            ]),
        ];

        foreach ($attempts as $what => $attempt) {
            $this->actingAs($foreignPortalUser, 'customer');

            $this->assertContains(
                $attempt()->status(),
                [403, 404],
                'Yad studiyanın müştərisi «'.$what.'» əməliyyatını edə bildi.',
            );
        }

        $this->assertSame(
            0,
            $ourBrief->fresh()->answers()->count(),
            'Yad studiyanın müştərisi bizim brifə cavab yazdı.',
        );

        // Bizim studiyanın işçisi yad layihənin brif icmalını görməməlidir.
        $this->actingAs($this->manager);
        app(TenantContext::class)->set($this->tenant->id);

        $status = $this->get(route('filament.app.resources.projects.brief-review', ['record' => $foreignProject->id]))->status();

        $this->assertContains($status, [403, 404], 'Menecer yad studiyanın brif icmalını açdı.');
    }

    /**
     * Razılaşdırma → qalıq borc tikişi.
     *
     * Studiya smeta sətrini müştəriyə göndərir, müştəri PORTALDAN «təsdiq»
     * deyir və məbləğ həmin anda qalıq borca düşməlidir. Bu iki modul ayrıca
     * işləyə-işləyə aralarındakı ötürmə qırıla bilər: razılaşdırma «təsdiq»
     * yazılır, smeta sətri isə `pending` qalır və pul heç vaxt borca çevrilmir.
     */
    public function test_a_client_approval_in_the_portal_turns_into_debt(): void
    {
        $this->actingAs($this->owner);
        app(TenantContext::class)->set($this->tenant->id);

        $project = $this->bareProject();
        $portalUser = ClientUser::create([
            'client_id' => $project->client_id,
            'name' => 'Razılaşdıran',
            'email' => 'approve@example.test',
        ]);

        $line = $project->budgetLines()->create([
            'work_type' => 'Vizualizasiya', 'unit' => 'ədəd', 'qty' => 4,
            'work_price' => 150, 'material_price' => 0, 'position' => 1,
            'visible_to_client' => true,
        ]);

        $approval = app(ApprovalService::class)->request($line, $this->manager);

        $this->assertSame(0.0, round((float) $project->fresh()->debt, 2), 'Göndərilmiş, amma təsdiqlənməmiş sətir artıq borc yaradıb.');

        $this->actingAs($portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $this->assertSame(ApprovalStatus::Approved, $line->fresh()->approval_status, 'Müştəri təsdiqlədi, smeta sətri isə köhnə statusda qaldı.');
        $this->assertSame(600.0, round((float) $project->fresh()->debt, 2), '4 × 150 = 600 ₼ borca düşməli idi.');

        // İkinci qərar qəbul edilməməlidir: təsdiqlənmişi rədd etmək borcu geri
        // alardı və müştəri artıq razılaşdığı işdən çıxa bilərdi.
        $this->actingAs($portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'reject', 'comment' => 'Fikrimi dəyişdim'])
            ->assertForbidden();

        $this->assertSame(600.0, round((float) $project->fresh()->debt, 2));
    }

    /**
     * Quick Brief-i portal endpointləri ilə doldurur — birbaşa modelə yazmaq
     * controller-in validasiyasını yan keçərdi və test həqiqəti göstərməzdi.
     */
    private function fillQuickBrief(Project $project, ClientUser $portalUser): Brief
    {
        $brief = app(BriefService::class)->forProject($project);
        app(BriefService::class)->switchTemplate($brief, BriefTemplate::where('key', 'quick')->firstOrFail());

        $section = BriefSection::where('key', 'quick_start')->firstOrFail();

        foreach ($this->quickAnswers() as $key => $value) {
            $question = BriefQuestion::where('brief_section_id', $section->id)->where('key', $key)->firstOrFail();

            $this->actingAs($portalUser, 'customer')
                ->patch(route('portal.brief.autosave', [$project, $section]), [
                    'question_id' => $question->id,
                    'value' => $value,
                    'delegated' => false,
                ])
                ->assertOk();
        }

        return $brief->fresh();
    }

    /** Hekayəyə girməyən, rəqəmləri təmiz qalsın deyə ayrıca qurulan layihə. */
    private function bareProject(): Project
    {
        return app(TenantContext::class)->actingAs($this->tenant->id, function (): Project {
            $client = Client::create(['name' => 'Təmiz müştəri '.uniqid(), 'status' => 'client']);

            return Project::create([
                'client_id' => $client->id,
                'name' => 'Təmiz layihə '.uniqid(),
                'type' => 'apartment',
                'status' => 'active',
                'manager_user_id' => $this->manager->id,
            ]);
        });
    }

    /**
     * Quick Brief-in bütün məcburi sualları + bir neçə könüllü cavab.
     *
     * @return array<string, mixed>
     */
    private function quickAnswers(): array
    {
        return [
            'object_type' => 'apartment',
            'object_address' => 'Bakı, Nizami küç. 12',
            'total_area_sqm' => '85',
            'property_readiness' => 'new_shell',
            'cooperation_scope' => 'design_only',
            'project_budget_range' => ['min' => 15000, 'max' => 25000],
            'style_preferences' => ['minimalism', 'scandi'],
            'special_requests' => 'İş masası pəncərə önündə olsun.',
            'contact_full_name' => 'Aysel Məmmədova',
            'contact_phone' => '+994 50 123 45 67',
            'pdpa_consent' => '1',
        ];
    }
}
