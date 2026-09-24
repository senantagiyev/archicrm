<?php

namespace Tests\Feature\QA2;

use App\Enums\Domain;
use App\Enums\StaffRole;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Widgets\CashForecastWidget;
use App\Filament\Widgets\MyTasksWidget;
use App\Filament\Widgets\OwnerStatsOverview;
use App\Filament\Widgets\PortfolioFinanceStats;
use App\Filament\Widgets\ProfitabilityWidget;
use App\Filament\Widgets\UpcomingDeadlinesWidget;
use App\Models\Approval;
use App\Models\AutomationRule;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BudgetLine;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Stage;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\PortalLoginLink;
use App\Services\Portal\InvitationService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA2 — ROLLAR, İCAZƏLƏR, STUDİYA İZOLYASİYASI VƏ AUTENTİFİKASİYA.
 *
 * Sahibkarın birinci tələbi: «bir studiya heç vaxt başqa studiyanın
 * tapşırıqlarını görməməlidir». İkinci tələb: «rol/icazə modulu həqiqətən
 * işləyirmi — istifadəçiyə rol vermək olurmu, hər modulun icazəsi düzgün
 * qurulubmu».
 *
 * Bu fayl mövcud QA testlərini TƏKRARLAMIR, genişləndirir:
 *  - `Scenarios/CrossStudioScenarioTest` iki studiyanın panel/portal/çat
 *    kəsişməsini ssenari kimi yoxlayır;
 *  - `Scenarios/StaffRoleScenarioTest` 6 baza rolunun gündəlik hərəkətlərini;
 *  - `Scenarios/DataExposureScenarioTest` müştəriyə/rola sızan sahələri;
 *  - `QA/TenantIsolationQaTest` və `QA/RolePermissionQaTest` model və matris
 *    səviyyəsində geniş sweep edir.
 * Burada isə SXEM ↔ KOD uyğunluğu (hər model üzrə tenant örtüyü), HƏR Filament
 * səthinin birbaşa URL ilə yoxlanması, portal magic-link autentifikasiyasının
 * bütün rədd halları və hüquq yükseltmə cəhdləri yoxlanılır.
 */
class RolesIsolationTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alpha;

    private StudioWorld $beta;

    /**
     * `tenant_id` sütunu OLMAYAN modellər və niyə qəsdən qlobaldır.
     * Hər sətir bir qərardır — yeni model bu siyahıya düşürsə, onun qlobal
     * qalması ŞÜURLU seçim olmalıdır, təsadüf yox.
     *
     * @var array<string, string>
     */
    private const INTENTIONALLY_GLOBAL = [
        // Paylaşılan kataloqlar: platforma səviyyəsində bir dəfə qurulur.
        'BriefTemplate' => 'Brif şablon bankı — platforma kataloqu',
        'BriefSection' => 'Brif bölmə bankı — platforma kataloqu',
        'BriefQuestion' => 'Brif sual bankı — platforma kataloqu',
        'StageTemplate' => 'Mərhələ şablonları — platforma kataloqu',
        'StageTemplateItem' => 'Mərhələ şablon sətirləri — valideyn şablonla gəlir',
        'Setting' => 'Qlobal sistem ayarları (panel səthi yoxdur)',
        'Translation' => 'Qlobal tərcümələr — yalnız platforma admini idarə edir',
        // `tenant_id` sütunu VAR, amma global scope yerinə copy-on-write
        // `forTenant()` scope-u ilə izolyasiya olunur (platforma sətri +
        // studiyanın öz sətri).
        'Role' => 'tenant_id VAR; Role::scopeForTenant + RoleResource::getEloquentQuery',
        'AutomationRule' => 'tenant_id VAR; AutomationRule::scopeForTenant ilə kölgələnir',
        // Valideyn vasitəsilə qorunan uşaq modellər: sorğu həmişə tenant-scoped
        // valideyndən başlayır (project/brief/client), özləri sütun daşımır.
        'BriefAnswer' => 'Brief vasitəsilə — brief_id ilə bağlıdır',
        'BriefComment' => 'Brief vasitəsilə',
        'BriefVersion' => 'Brief vasitəsilə',
        'BriefRoom' => 'Brief vasitəsilə',
        'BriefSectionState' => 'Brief vasitəsilə',
        'ChatMessage' => 'Project vasitəsilə — ChatService sorğuları project_id ilə kəsir',
        'Comment' => 'Polimorf — valideyn qeyd vasitəsilə',
        'ClientContactLog' => 'Client vasitəsilə',
        'Tenant' => 'Studiya reyestrinin özü — yalnız platforma admini',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = StudioWorld::make('alpha');
        $this->beta = StudioWorld::make('beta');
    }

    // =====================================================================
    // 1. MODEL × tenant_id ÖRTÜ CƏDVƏLİ
    // =====================================================================

    /**
     * Sxem ilə kod arasındaki boşluq ən təhlükəli boşluqdur: `tenant_id`
     * sütunu olan, amma `BelongsToTenant` işlətməyən model heç bir filtrdən
     * keçmir — yəni başqa studiyanın sətirlərini SAKİTCƏ qaytarır.
     */
    public function test_every_model_with_a_tenant_id_column_is_under_the_global_scope(): void
    {
        $missing = [];
        $covered = [];

        foreach ($this->models() as $name => $class) {
            $table = (new $class)->getTable();

            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            if (in_array($name, ['Role', 'AutomationRule'], true)) {
                // Copy-on-write kataloqu: global scope yoxdur, `forTenant()` var.
                $this->assertTrue(
                    method_exists($class, 'scopeForTenant'),
                    "{$name} paylaşılan kataloqdur, amma forTenant() scope-u yoxdur.",
                );

                continue;
            }

            if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $missing[] = "{$name} ({$table})";

                continue;
            }

            $covered[$name] = $table;
        }

        $this->assertSame([], $missing, 'BLOKER: tenant_id sütunu var, global scope yoxdur: '.implode(', ', $missing));
        $this->assertGreaterThan(20, count($covered), 'Örtülən model sayı gözlənildiyindən azdır — trait silinmiş ola bilər.');
    }

    /** Əksi də doğru olmalıdır: trait var, sütun yoxdursa scope SQL xətası verir. */
    public function test_every_model_using_the_trait_really_has_the_column(): void
    {
        foreach ($this->models() as $name => $class) {
            if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                continue;
            }

            $table = (new $class)->getTable();

            $this->assertTrue(
                Schema::hasColumn($table, 'tenant_id'),
                "{$name} BelongsToTenant işlədir, amma {$table}.tenant_id yoxdur — hər sorğu SQL xətası verəcək.",
            );
        }
    }

    /** `tenant_id`-siz modellərin hamısı ŞÜURLU qərar siyahısında olmalıdır. */
    public function test_models_without_a_tenant_id_are_declared_global_on_purpose(): void
    {
        $undeclared = [];

        foreach ($this->models() as $name => $class) {
            $table = (new $class)->getTable();

            if (Schema::hasColumn($table, 'tenant_id') && in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                continue;
            }

            if (! array_key_exists($name, self::INTENTIONALLY_GLOBAL)) {
                $undeclared[] = "{$name} ({$table})";
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            'Bu modellər nə tenant-scoped, nə də qəsdən qlobal kimi elan edilib: '.implode(', ', $undeclared),
        );
    }

    /**
     * Örtüyün İŞLƏDİYİNİN sübutu: alpha studiyasının hər sətri beta
     * kontekstində YOXA çıxmalıdır — id ilə birbaşa axtarışda da.
     */
    public function test_no_tenanted_model_returns_a_foreign_row_in_another_studios_context(): void
    {
        $leaks = [];

        foreach ($this->models() as $name => $class) {
            if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                continue;
            }

            /** @var Model $probe */
            $probe = app(TenantContext::class)->actingAs($this->alpha->tenant->id, function () use ($class) {
                $query = $class::query();

                if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                    $query->withTrashed();
                }

                return $query->first();
            });

            if ($probe === null) {
                continue; // bu modeldən nümunə sətir yoxdur
            }

            app(TenantContext::class)->actingAs($this->beta->tenant->id, function () use ($class, $probe, $name, &$leaks) {
                $query = $class::query()->whereKey($probe->getKey());

                if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                    $query->withTrashed();
                }

                if ($query->exists()) {
                    $leaks[] = "{$name}#{$probe->getKey()}";
                }
            });
        }

        $this->assertSame([], $leaks, 'BLOKER: beta studiyası alpha sətirlərini id ilə oxuyur: '.implode(', ', $leaks));
    }

    // =====================================================================
    // 2. ROL × DOMEN — HƏR FİLAMENT SƏTHİ BİRBAŞA URL İLƏ
    // =====================================================================

    /**
     * HƏR resursun siyahı səhifəsi HƏR baza rolu üçün: matrisdə elan edilən
     * səviyyə ilə HTTP cavabı eyni olmalıdır. «Naviqasiyada gizlidir» kifayət
     * deyil — birbaşa URL 403 verməlidir.
     *
     * Gözlənti matrisin ÖZÜNDƏN hesablanır (`AccessMatrix::level`), policy-dən
     * yox: belə olduqda policy matrisdən ayrılan kimi test qırılır.
     */
    #[DataProvider('roleProvider')]
    public function test_every_resource_list_page_matches_the_declared_matrix(string $role): void
    {
        $user = $this->alpha->user($role);

        foreach (self::RESOURCE_GATES as $slug => [$model, $domain, $level, $createLevel]) {
            $expected = $this->matrixAllows($user, $domain, $level);

            $status = $this->statusFor($user, "filament.app.resources.{$slug}.index");

            $this->assertSame(
                $expected ? 200 : 403,
                $status,
                "[{$role}] {$slug} siyahısı: matris «{$domain}» üzrə "
                .($expected ? 'icazə verir, URL isə bağlıdır' : 'icazə vermir, URL isə AÇIQDIR')
                ." (status {$status}).",
            );

            // Policy qatı da eyni cavabı verməlidir (servis/API yolu üçün).
            $this->assertSame(
                $expected,
                $user->can('viewAny', $model),
                "[{$role}] {$slug}: policy viewAny() matrisdən fərqli cavab verir.",
            );
        }
    }

    /** Yaratma səhifəsi ayrı gate-dir: baxış hüququ yazma hüququ demək deyil. */
    #[DataProvider('roleProvider')]
    public function test_every_resource_create_page_matches_the_declared_matrix(string $role): void
    {
        $user = $this->alpha->user($role);

        foreach (self::RESOURCE_GATES as $slug => [$model, $domain, $level, $createLevel]) {
            if ($createLevel === null) {
                continue; // resursda create səhifəsi yoxdur
            }

            $expected = $this->matrixAllows($user, $domain, $createLevel);
            $status = $this->statusFor($user, "filament.app.resources.{$slug}.create");

            $this->assertSame(
                $expected ? 200 : 403,
                $status,
                "[{$role}] {$slug} yaratma səhifəsi gözlənildiyi kimi deyil (status {$status}).",
            );
        }
    }

    /** Xüsusi səhifələr: Dashboard, Attention, Calendar, ChatCenter, Profitability, TaskPlanner. */
    #[DataProvider('roleProvider')]
    public function test_every_custom_page_matches_the_declared_matrix(string $role): void
    {
        $user = $this->alpha->user($role);

        $expectations = [
            // Dashboard hər panel istifadəçisinə açıqdır, məzmunu isə rola görə kəsilir.
            'dashboard' => true,
            'attention' => $this->matrixAllows($user, 'analytics', 1),
            'calendar' => $this->matrixAllows($user, 'stages_tasks', 1),
            'chat-center' => $this->matrixAllows($user, 'projects', 1),
            'profitability' => $this->matrixAllows($user, 'analytics', 1) && $this->matrixAllows($user, 'payments', 3),
            'task-planner' => $this->matrixAllows($user, 'stages_tasks', 1),
        ];

        foreach ($expectations as $page => $expected) {
            $status = $this->statusFor($user, "filament.app.pages.{$page}");

            $this->assertSame(
                $expected ? 200 : 403,
                $status,
                "[{$role}] «{$page}» səhifəsi gözlənildiyi kimi deyil (status {$status}).",
            );
        }
    }

    /** Vidjetlər: pul rəqəmi daşıyan vidjet icazəsiz rola GÖRÜNMƏMƏLİDİR. */
    #[DataProvider('roleProvider')]
    public function test_every_widget_visibility_matches_the_declared_matrix(string $role): void
    {
        $user = $this->alpha->user($role);
        $this->actingAs($user);

        $money = $this->matrixAllows($user, 'analytics', 1) && $this->matrixAllows($user, 'payments', 3);

        $expectations = [
            PortfolioFinanceStats::class => $money,
            CashForecastWidget::class => $money,
            ProfitabilityWidget::class => $money,
            OwnerStatsOverview::class => $this->matrixAllows($user, 'owner_dashboard', 1),
            UpcomingDeadlinesWidget::class => $this->matrixAllows($user, 'stages_tasks', 3),
            // Öz tapşırıqları — hər işçiyə açıqdır (gate yoxdur).
            MyTasksWidget::class => true,
        ];

        foreach ($expectations as $widget => $expected) {
            $this->assertSame(
                $expected,
                $widget::canView(),
                "[{$role}] {$widget}::canView() gözlənildiyi kimi deyil.",
            );
        }
    }

    /**
     * Konkret sətir səviyyəsi: icazəsi olmayan rol həm siyahını, həm də sətrin
     * redaktə URL-ini görməməlidir. Filament sətri tapmadıqda 404 da verə bilər
     * (tenant scope), ona görə «200 OLMASIN» yoxlanılır.
     */
    public function test_a_denied_role_cannot_open_a_record_edit_page_by_direct_url(): void
    {
        $invoice = app(TenantContext::class)->actingAs($this->alpha->tenant->id, fn () => Invoice::create([
            'project_id' => $this->alpha->project->id,
            'client_id' => $this->alpha->client->id,
            'number' => 'QA2-001',
            'status' => 'draft',
            'total' => 1000,
        ]));

        // İCAZƏLİ hal ƏVVƏLCƏ yoxlanılır. Səbəb test infrastrukturudur: Filament
        // səhifəsi sətri tapmayanda (404) Livewire-in `Redirector` bağlaması bu
        // PHP prosesində qalır və HƏMİN prosesdəki NÖVBƏTİ sorğu 500 verir.
        // Real mühitdə hər sorğu ayrı prosesdir, ona görə bu yalnız test
        // sıralamasına təsir edir — amma sıralamaya əməl etmək lazımdır.
        $this->assertSame(
            200,
            $this->actingAs($this->alpha->user('accountant'))
                ->get(route('filament.app.resources.invoices.edit', ['record' => $invoice->id]))
                ->status(),
            'Mühasib öz studiyasının hesab-fakturasını aça bilmir — matris Ödənişlər = Tam deyir.',
        );

        foreach (['designer', 'visualizer', 'procurement'] as $role) {
            $status = $this->actingAs($this->alpha->user($role))
                ->get(route('filament.app.resources.invoices.edit', ['record' => $invoice->id]))
                ->status();

            $this->assertNotSame(200, $status, "[{$role}] ödəniş hüququ olmadan hesab-fakturanı açır (status {$status}).");
        }
    }

    /**
     * Livewire qatı: gizlədilmiş düymə YETƏRLİ müdafiə deyil — hücumçu düyməyə
     * basmır, `mountAction` çağırışını birbaşa göndərir. `callAction()` köməkçisi
     * əvvəlcə görünürlüyü yoxladığı üçün BURADA istifadə edilmir; xam
     * `call('mountAction', ...)` göndərilir.
     */
    public function test_a_hidden_livewire_action_is_refused_not_just_hidden(): void
    {
        // Platforma səviyyəli (tenant_id = null) sistem rolu: RoleResource-da
        // silmə düyməsi GİZLƏDİLİB, çünki o sətir BÜTÜN studiyalara aiddir.
        $platformRole = Role::create([
            'tenant_id' => null,
            'key' => 'qa2_platform_role',
            'name' => 'QA2 platforma rolu',
            'levels' => ['projects' => 1],
            'own_projects_only' => false,
            'is_system' => true,
            'active' => true,
        ]);

        Livewire::actingAs($this->alpha->user('owner'))
            ->test(ListRoles::class)
            ->call('mountAction', 'delete', ['record' => $platformRole->getKey()], ['table' => true, 'recordKey' => (string) $platformRole->getKey()])
            ->call('callMountedAction');

        $this->assertTrue(
            Role::query()->whereKey($platformRole->id)->exists(),
            'BLOKER: bir studiya platforma rolunu (bütün studiyaların rolunu) silə bildi.',
        );
    }

    // =====================================================================
    // 3. XÜSUSİ ROLLAR — KONSTRUKTOR, DƏRHAL TƏSİR, KORLANMIŞ DƏYƏRLƏR
    // =====================================================================

    /**
     * Rol konstruktoru HƏQİQƏTƏN işləyirmi: sahibkar RoleResource formasından
     * rol yaradır, işçiyə verir və icazə DƏRHAL (əlavə flush olmadan) tətbiq
     * olunur.
     */
    public function test_a_role_created_through_the_resource_grants_access_immediately(): void
    {
        $owner = $this->alpha->user('owner');

        Livewire::actingAs($owner)
            ->test(CreateRole::class)
            ->fillForm([
                'key' => 'qa2_smetaci',
                'name' => 'QA2 smetaçı',
                'own_projects_only' => false,
                'active' => true,
                'levels' => [
                    'clients' => 0, 'projects' => 1, 'brief' => 0, 'stages_tasks' => 0,
                    'files_documents' => 0, 'budget' => 3, 'procurement' => 0,
                    'payments' => 0, 'owner_dashboard' => 0, 'analytics' => 0,
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::query()->where('key', 'qa2_smetaci')->firstOrFail();

        // Studiyaya möhürlənməlidir: tenant_id = null olsa, rol BÜTÜN studiyaların
        // siyahısına düşür və başqa studiyada da təyin edilə bilər.
        $this->assertSame($this->alpha->tenant->id, $role->tenant_id, 'Yeni rol platforma sətri kimi yaradıldı.');
        $this->assertFalse($role->is_system, 'Xüsusi rol sistem rolu kimi işarələndi.');

        $user = app(TenantContext::class)->actingAs($this->alpha->tenant->id, fn () => User::create([
            'name' => 'QA2 smetaçı işçi',
            'email' => 'qa2-smetaci@alpha.test',
            'password' => 'secret123',
            'role' => 'visualizer',
            'role_id' => $role->id,
            'is_active' => true,
        ]));

        // Baza rolu (vizualizator) Büdcə = Yoxdur deyir; xüsusi rol Tam deyir —
        // effektiv səviyyə xüsusi roldan gəlməlidir.
        $this->assertSame(3, AccessMatrix::level($user->fresh(), Domain::Budget)->value);
        $this->assertTrue($user->fresh()->can('viewAny', BudgetLine::class));

        // Domeni geri alırıq — DƏRHAL təsir etməlidir (keş invalidasiyası).
        Livewire::actingAs($owner)
            ->test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm(['levels.budget' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            0,
            AccessMatrix::level($user->fresh(), Domain::Budget)->value,
            'Domen geri alındı, amma istifadəçi hələ də səviyyəni saxlayır — keş invalidasiyası işləmir.',
        );
    }

    /**
     * Bazadaki KORLANMIŞ səviyyə dəyəri (miqrasiya, import, əl ilə redaktə) icazə
     * RƏDDİ verməlidir, 500 yox. `AccessLevel::from()` diapazondan kənar tam ədədə
     * `ValueError` atır — bu, rol modulunu bütövlükdə sındırardı.
     */
    public function test_a_corrupt_level_value_in_the_database_denies_instead_of_crashing(): void
    {
        $role = Role::create([
            'tenant_id' => $this->alpha->tenant->id,
            'key' => 'qa2_korlanmis',
            'name' => 'QA2 korlanmış rol',
            // 7 AccessLevel-də yoxdur; `budget` sətri isə tamam yad tipdədir.
            'levels' => ['projects' => 7, 'budget' => 'hə', 'payments' => -1],
            'own_projects_only' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $user = app(TenantContext::class)->actingAs($this->alpha->tenant->id, fn () => User::create([
            'name' => 'QA2 korlanmış rollu işçi',
            'email' => 'qa2-korlanmis@alpha.test',
            'password' => 'secret123',
            'role' => 'designer',
            'role_id' => $role->id,
            'is_active' => true,
        ]));

        AccessMatrix::flushCache();

        foreach (Domain::cases() as $domain) {
            $level = AccessMatrix::level($user, $domain);

            $this->assertContains(
                $level->value,
                [0, 1, 2, 3],
                "«{$domain->value}» domenində korlanmış dəyər etibarlı səviyyəyə çevrilmədi.",
            );
        }

        // Diapazondan kənar dəyər GENİŞ icazə kimi oxunmamalıdır.
        $this->assertSame(0, AccessMatrix::level($user, Domain::Projects)->value, 'Korlanmış «7» dəyəri icazə kimi qəbul edildi.');
        $this->assertSame(0, AccessMatrix::level($user, Domain::Payments)->value, 'Mənfi səviyyə icazə kimi qəbul edildi.');

        // Panel açılır, sadəcə boş — 500 vermir.
        $this->assertNotSame(500, $this->statusFor($user, 'filament.app.pages.dashboard'));
    }

    /**
     * İdarəetmə səthləri (Rollar, Komanda, Studiyalar, Tərcümələr) — birbaşa URL
     * ilə. Rol konstruktoru özünü də idarə edir: ona çıxışı olmayan rol nə
     * siyahını, nə yaratma səhifəsini görməməlidir.
     */
    public function test_governance_surfaces_are_closed_to_every_non_governing_role(): void
    {
        $governance = [
            'filament.app.resources.roles.index',
            'filament.app.resources.roles.create',
            'filament.app.resources.users.index',
            'filament.app.resources.users.create',
            'filament.app.resources.automation-rules.index',
        ];

        // Platforma səviyyəli səthlər: studiya sahibkarı da daxil olmaqla HEÇ KİM.
        $platformOnly = [
            'filament.app.resources.tenants.index',
            'filament.app.resources.tenants.create',
            'filament.app.resources.translations.index',
        ];

        foreach (['project_manager', 'designer', 'visualizer', 'procurement', 'accountant'] as $role) {
            $user = $this->alpha->user($role);
            $this->actingAs($user);

            $this->assertFalse(RoleResource::canAccess(), "[{$role}] Rollar resursunu görür.");
            $this->assertFalse(TenantResource::canAccess(), "[{$role}] Studiyalar resursunu görür.");

            foreach (array_merge($governance, $platformOnly) as $route) {
                $status = $this->statusFor($user, $route);
                $this->assertSame(403, $status, "[{$role}] «{$route}» açıqdır (status {$status}).");
            }
        }

        // Sahibkar idarəetməni görür, PLATFORMA reyestrini isə görmür.
        $owner = $this->alpha->user('owner');
        $this->actingAs($owner);
        $this->assertTrue(RoleResource::canAccess(), 'Sahibkar rol konstruktoruna çıxa bilmir.');
        $this->assertFalse(TenantResource::canAccess(), 'Studiya sahibkarı platforma reyestrini görür.');

        foreach ($governance as $route) {
            $this->assertSame(200, $this->statusFor($owner, $route), "Sahibkar «{$route}» səthini aça bilmir.");
        }

        foreach ($platformOnly as $route) {
            $this->assertSame(403, $this->statusFor($owner, $route), "Studiya sahibkarı «{$route}» səthini açır.");
        }
    }

    /**
     * TAPINTI [ORTA] — DÜZƏLDİLDİ; bu test artıq qorumadır.
     *
     * Təyin edilmiş xüsusi rol silinəndə `users.role_id` FK-ya görə `null`
     * olurdu və istifadəçi səssizcə BAZA rolunun hüquqlarına qayıdırdı. Baza
     * rolu daha geniş olduqda bu, səssiz hüquq ARTIMI idi: məhdudlaşdırmaq üçün
     * yaradılmış rolu silmək məhdudiyyəti də silirdi.
     *
     * İndi `Role::booted()` daşıyıcısı qalan rolun silinməsini bloklayır —
     * modeldə saxlanılır ki, konsol və idxal yolu da eyni qaydadan keçsin.
     */
    public function test_deleting_a_role_that_is_still_assigned_is_blocked(): void
    {
        $role = Role::create([
            'tenant_id' => $this->alpha->tenant->id,
            'key' => 'qa2_mehdud_sahibkar',
            'name' => 'QA2 məhdud sahibkar',
            'levels' => array_fill_keys(array_map(fn (Domain $d) => $d->value, Domain::cases()), 0),
            'own_projects_only' => true,
            'is_system' => false,
            'active' => true,
        ]);

        $user = app(TenantContext::class)->actingAs($this->alpha->tenant->id, fn () => User::create([
            'name' => 'QA2 məhdud sahibkar işçi',
            'email' => 'qa2-mehdud@alpha.test',
            'password' => 'secret123',
            'role' => 'owner',          // baza rolu GENİŞ
            'role_id' => $role->id,     // xüsusi rol isə hər şeyi bağlayır
            'is_active' => true,
        ]));

        AccessMatrix::flushCache();
        $this->assertSame(0, AccessMatrix::level($user->fresh(), Domain::Payments)->value, 'Xüsusi rol tətbiq olunmur.');

        try {
            $role->delete();
            $this->fail('Daşıyıcısı olan rol silindi — işçi səssizcə baza rolunun hüquqlarına qayıtdı.');
        } catch (\RuntimeException) {
            // Gözlənilən: silmə bloklanır.
        }

        AccessMatrix::flushCache();

        $this->assertNotNull(Role::find($role->id), 'Rol silinməməli idi.');
        $this->assertSame($role->id, $user->fresh()->role_id, 'İşçi hələ də həmin rolda qalmalıdır.');
        $this->assertSame(
            0,
            AccessMatrix::level($user->fresh(), Domain::Payments)->value,
            'Məhdudiyyət qüvvədə qalmalıdır.',
        );

        // Rolu silmək üçün əvvəlcə daşıyıcılar boşaldılmalıdır — o yol açıq qalır.
        $user->forceFill(['role_id' => null])->save();
        $role->delete();

        $this->assertNull(Role::find($role->id), 'Boş rol silinə bilməlidir.');
    }

    // =====================================================================
    // 4. DEAKTİV İŞÇİ VƏ SetTenant-İN BAĞLI QAPISI
    // =====================================================================

    /**
     * `is_active = false` HƏR YERDƏ işləməlidir: panel, policy qatı və panel-dən
     * KƏNAR «API kimi» marşrutlar (təqvim feed-i, fayl endirmə, çat).
     */
    public function test_a_deactivated_employee_loses_access_on_every_surface(): void
    {
        Storage::fake('public');
        $pm = $this->alpha->user('project_manager');
        $file = $this->alpha->internalFile;
        Storage::disk('public')->put($file->file_path, 'cizgi');

        // Əvvəl işlədiyini sübut edirik, yoxsa test heç nə göstərmir.
        $this->assertSame(200, $this->statusFor($pm, 'filament.app.pages.dashboard'));
        $this->assertSame(200, $this->statusFor($pm, 'calendar.events'));
        $this->assertSame(200, $this->statusFor($pm, 'files.download', ['file' => $file->id]));

        $pm->forceFill(['is_active' => false])->saveQuietly();
        AccessMatrix::flushCache();
        $pm = $pm->fresh();

        $this->assertSame(403, $this->statusFor($pm, 'filament.app.pages.dashboard'), 'Deaktiv işçi paneli açır.');
        $this->assertSame(403, $this->statusFor($pm, 'filament.app.resources.projects.index'), 'Deaktiv işçi layihə siyahısını açır.');

        // Fayl endirmə policy-dən keçir — deaktiv hesab üçün bağlı olmalıdır.
        $this->assertSame(403, $this->statusFor($pm, 'files.download', ['file' => $file->id]), 'Deaktiv işçi fayl endirir.');

        // Təqvim feed-i 200 qaytarır, amma İÇİ boş olmalıdır: deaktiv hesab heç
        // bir layihənin hadisəsini görməməlidir.
        $this->flushSession();
        $events = $this->actingAs($pm)->getJson(route('calendar.events'))->json();
        $this->assertSame([], $events, 'Deaktiv işçi təqvim feed-ində hələ də hadisə görür.');

        // Policy qatı: heç bir domendə heç nə.
        foreach (Domain::cases() as $domain) {
            $this->assertSame(0, AccessMatrix::level($pm, $domain)->value, "Deaktiv işçi «{$domain->value}» domenində səviyyə saxlayır.");
        }
    }

    /**
     * Deaktiv işçinin çat sayğacı: mesaj MƏTNİ bağlıdır (`poll` policy-dən keçir),
     * lakin ümumi oxunmamış SAYI hələ də qaytarılır.
     *
     * Bu, aşkar edilmiş tapıntının qeydidir (ORTA): `/staff-chat/unread-count`
     * marşrutunda `auth:web`-dən başqa yoxlama yoxdur və `ChatService::
     * staffProjectIds()` deaktiv hesab üçün də layihə id-lərini qaytarır.
     * Düzəliş `app/Http/Controllers/Staff/ChatController.php` və
     * `app/Services/Chat/ChatService.php` fayllarındadır — onlar bu auditin
     * sahəsindən kənardır, ona görə burada FAKT kimi sənədləşdirilir.
     */
    public function test_deactivated_employee_chat_message_bodies_are_closed(): void
    {
        $pm = $this->alpha->user('project_manager');
        $pm->forceFill(['is_active' => false])->saveQuietly();
        AccessMatrix::flushCache();
        $pm = $pm->fresh();

        // Mesaj məzmunu qapalıdır — əsas olan budur.
        $this->assertSame(
            403,
            $this->statusFor($pm, 'staff.chat.poll', ['project' => $this->alpha->project->id]),
            'Deaktiv işçi çat lentini oxuyur.',
        );

        // Sayğac marşrutu isə hələ də cavab verir (tapıntı, ORTA).
        $this->flushSession();
        $count = $this->actingAs($pm)->getJson(route('staff.chat.unread'));
        $this->assertSame(200, $count->status());
    }

    /** SetTenant bağlı qapı prinsipi ilə işləyir: studiyası olmayan hesab keçmir. */
    public function test_set_tenant_fails_closed_for_a_tenantless_account(): void
    {
        // Ən azı iki studiya var (setUp + miqrasiyanın defolt studiyası) — yəni
        // izolyasiya edilməli bir şey var və SetTenant «bağlı qapı» rejimindədir.
        $this->assertGreaterThanOrEqual(2, Tenant::query()->count());

        $orphan = User::withoutGlobalScopes()->create([
            'name' => 'Studiyasız işçi',
            'email' => 'orphan@qa2.test',
            'password' => 'secret123',
            'role' => 'owner',
            'is_active' => true,
            'tenant_id' => null,
        ]);

        $this->assertSame(
            403,
            $this->statusFor($orphan, 'filament.app.pages.dashboard'),
            'BLOKER: studiyaya bağlı olmayan hesab paneli açır — global scope sönür, sessiya studiyalar arası super-istifadəçi olur.',
        );

        // Platforma admini isə reyestri idarə etmək üçün keçir.
        $platformAdmin = User::withoutGlobalScopes()->create([
            'name' => 'Platforma admini',
            'email' => 'platform@qa2.test',
            'password' => 'secret123',
            'role' => 'owner',
            'is_active' => true,
            'is_platform_admin' => true,
            'tenant_id' => null,
        ]);

        $this->assertSame(200, $this->statusFor($platformAdmin, 'filament.app.resources.tenants.index'));
    }

    /** Studiya deaktiv edilibsə HƏR İKİ qapı bağlanır: panel və portal. */
    public function test_a_deactivated_studio_closes_both_doors(): void
    {
        $this->beta->tenant->forceFill(['active' => false])->save();

        // Alpha ƏVVƏLCƏ yoxlanılır: deaktivasiya YALNIZ bir studiyaya aiddir.
        // Sıra vacibdir — «customer» qapısına sorğudan SONRA eyni test prosesində
        // panel sorğusu Filament-in sessiya vəziyyətinə görə 403 verir; bu, test
        // artefaktıdır (real mühitdə hər sorğu ayrı prosesdir).
        $this->assertSame(
            200,
            $this->statusFor($this->alpha->user('owner'), 'filament.app.pages.dashboard'),
            'Bir studiyanın deaktivasiyası digərini də bağladı.',
        );

        $this->assertSame(
            403,
            $this->statusFor($this->beta->user('owner'), 'filament.app.pages.dashboard'),
            'Deaktiv studiyanın işçisi paneli açır.',
        );

        // Portal qapısı da bağlıdır: əks halda işçilər bağlı, müştərilər açıq qalardı.
        $this->flushSession();
        $this->actingAs($this->beta->portalUser, 'customer')
            ->get(route('portal.home'))
            ->assertForbidden();
    }

    /**
     * Studiya dəyişməsi keşi çirkləndirmir: bir proses içində iki studiyanın
     * sahibkarı üçün matris ayrı-ayrı həll olunmalıdır (avtomatlaşdırma tick-i
     * bir gedişdə bütün studiyaları dolanır).
     */
    public function test_switching_tenant_does_not_serve_the_previous_studios_matrix(): void
    {
        // Beta-nın sahibkar rolunu studiya daxilində «kasıblaşdırırıq».
        $betaRole = Role::create([
            'tenant_id' => $this->beta->tenant->id,
            'key' => 'owner',
            'name' => 'Beta sahibkar (məhdud)',
            'levels' => array_fill_keys(array_map(fn (Domain $d) => $d->value, Domain::cases()), 0),
            'own_projects_only' => true,
            'is_system' => false,
            'active' => true,
        ]);

        AccessMatrix::flushCache();

        $alphaOwner = $this->alpha->user('owner');
        $betaOwner = $this->beta->user('owner');

        // Ardıcıl oxunuş: alpha → beta → alpha. Keş açarı studiyanı saymırsa,
        // ikinci və üçüncü cavab birincinin kopyası olar.
        $this->assertSame(3, AccessMatrix::level($alphaOwner, Domain::Payments)->value);
        $this->assertSame(0, AccessMatrix::level($betaOwner, Domain::Payments)->value, 'Beta sahibkarı alpha-nın matrisini aldı — keş studiyalar arası sızır.');
        $this->assertSame(3, AccessMatrix::level($alphaOwner, Domain::Payments)->value);

        $this->assertSame($this->beta->tenant->id, $betaRole->tenant_id);
    }

    // =====================================================================
    // 5. PORTAL AUTENTİFİKASİYASI — MAGİC LİNK RƏDD HALLARI
    // =====================================================================

    /** Vaxtı keçmiş link işləməməlidir (imza müddəti). */
    public function test_an_expired_magic_link_is_refused(): void
    {
        $url = $this->magicLinkFor($this->alpha->portalUser);

        $this->travel(31)->minutes();

        $this->get($url)->assertForbidden();
        $this->assertGuest('customer');
    }

    /** İmzası dəyişdirilmiş link işləməməlidir. */
    public function test_a_tampered_magic_link_is_refused(): void
    {
        $url = $this->magicLinkFor($this->alpha->portalUser);

        // İmzanı saxlayıb HƏDƏFİ dəyişmək: başqa müştərinin hesabına keçid cəhdi.
        $tampered = str_replace(
            '/portal/magic/'.$this->alpha->portalUser->id,
            '/portal/magic/'.$this->alpha->secondPortalUser->id,
            $url,
        );

        $this->get($tampered)->assertForbidden();
        $this->assertGuest('customer');

        // Token-i dəyişmək də kifayət etmir (imza onu da qoruyur).
        $this->get(preg_replace('/t=[^&]+/', 't=oğurlanmış', $url))->assertForbidden();
        $this->assertGuest('customer');

        // BAŞQA STUDİYANIN müştərisinə yönəltmək: link studiyalar arası keçid
        // vasitəsi olmamalıdır.
        $crossStudio = str_replace(
            '/portal/magic/'.$this->alpha->portalUser->id,
            '/portal/magic/'.$this->beta->portalUser->id,
            $url,
        );

        $this->get($crossStudio)->assertForbidden();
        $this->assertGuest('customer');
    }

    /** Portal girişi ləğv edilibsə (hesab silinib) köhnə link ölür. */
    public function test_a_magic_link_dies_when_the_portal_account_is_revoked(): void
    {
        $portalUser = $this->alpha->portalUser;
        $url = $this->magicLinkFor($portalUser);

        // Ləğv etmək = hesabı soft-delete etmək (InvitationService qaydası).
        app(TenantContext::class)->actingAs($this->alpha->tenant->id, fn () => $portalUser->delete());

        $this->get($url)->assertNotFound();
        $this->assertGuest('customer');
    }

    /** Başqa studiyanın müştərisi üçün olan link o studiyanın panelinə yol açmır. */
    public function test_a_portal_session_never_reaches_the_admin_panel(): void
    {
        $url = $this->magicLinkFor($this->beta->portalUser);
        $this->get($url)->assertRedirect(route('portal.home'));

        // Portal sessiyası ilə panel: giriş səhifəsinə yönləndirilir, 200 ALMAZ.
        $panel = $this->get(route('filament.app.pages.dashboard'));
        $this->assertNotSame(200, $panel->status(), 'Portal sessiyası admin panelini açır.');

        // Və portal sessiyası başqa studiyanın layihəsini görmür.
        $this->get(route('portal.projects.show', ['project' => $this->alpha->project->id]))
            ->assertNotFound();
    }

    /** Emal edilmiş linkin ÖZÜ (e-poçtdan) yoxlanılır — yenidən imzalanmış surroqat yox. */
    private function magicLinkFor(ClientUser $clientUser): string
    {
        Notification::fake();

        app(InvitationService::class)->sendLoginLink($clientUser);

        $link = null;

        Notification::assertSentTo($clientUser, PortalLoginLink::class, function (PortalLoginLink $notification) use (&$link) {
            $link = $notification->link;

            return true;
        });

        $this->assertNotNull($link, 'Giriş linki göndərilmədi.');

        return $link;
    }

    // =====================================================================
    // 6. HÜQUQ YÜKSƏLTMƏ CƏHDLƏRİ
    // =====================================================================

    /** İdarəetmə hüququ olmayan işçi nə özünə, nə başqasına rol verə bilməz. */
    public function test_a_non_governing_employee_cannot_grant_itself_a_higher_role(): void
    {
        $pm = $this->alpha->user('project_manager');

        $this->assertFalse($pm->can('create', User::class), 'Layihə meneceri işçi yarada bilir.');
        $this->assertFalse($pm->can('update', $pm), 'Layihə meneceri öz hesabını (rolunu) redaktə edə bilir.');
        $this->assertFalse($pm->can('update', $this->alpha->user('designer')), 'Layihə meneceri başqasının rolunu dəyişə bilir.');

        // Livewire qatı: səhifəni mount etmək cəhdi 403-dür.
        // Birbaşa URL: öz kartı da, başqasının kartı da bağlıdır.
        $this->assertSame(403, $this->statusFor($pm, 'filament.app.resources.users.edit', ['record' => $pm->getKey()]));
        $this->assertSame(403, $this->statusFor($pm, 'filament.app.resources.users.edit', ['record' => $this->alpha->user('designer')->getKey()]));

        // Livewire komponenti də bağlıdır — «naviqasiyada görünmür» kifayət deyil.
        Livewire::actingAs($pm)->test(CreateUser::class)->assertForbidden();
        Livewire::actingAs($pm)->test(EditUser::class, ['record' => $pm->getKey()])->assertForbidden();
        Livewire::actingAs($pm)->test(ListRoles::class)->assertForbidden();

        $this->assertSame(
            StaffRole::ProjectManager,
            $pm->fresh()->role,
            'Rol dəyişdi — yükseltmə cəhdi keçdi.',
        );
    }

    /**
     * `is_platform_admin` HEÇ BİR formada yoxdur: onu ala bilən adam bütün
     * studiyaların reyestrini idarə edərdi. Livewire state-inə əlavə açar
     * yazmaq da kömək etməməlidir (Filament yalnız sxemdəki sahələri oxuyur).
     */
    public function test_platform_admin_flag_cannot_be_set_through_the_team_form(): void
    {
        $owner = $this->alpha->user('owner');

        Livewire::actingAs($owner)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'QA2 yeni işçi',
                'email' => 'qa2-yeni@alpha.test',
                'role' => 'designer',
                'password' => 'secret123',
                'is_active' => true,
            ])
            ->set('data.is_platform_admin', true)
            ->set('data.tenant_id', $this->beta->tenant->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::withoutGlobalScopes()->where('email', 'qa2-yeni@alpha.test')->firstOrFail();

        $this->assertFalse((bool) $created->is_platform_admin, 'BLOKER: forma platforma admini bayrağını yazdı.');
        $this->assertSame(
            $this->alpha->tenant->id,
            $created->tenant_id,
            'BLOKER: işçi BAŞQA studiyada yaradıldı — sahibkar yad studiyaya hesab əlavə edə bilir.',
        );
    }

    /** Bir studiyanın sahibkarı digər studiyanın işçisini görə/redaktə edə bilməz. */
    public function test_an_owner_cannot_touch_another_studios_employee(): void
    {
        $alphaOwner = $this->alpha->user('owner');
        $betaDesigner = $this->beta->user('designer');

        $this->assertFalse($alphaOwner->can('update', $betaDesigner), 'Policy yad studiyanın işçisini redaktə etməyə icazə verir.');

        $this->assertNotSame(
            200,
            $this->statusFor($alphaOwner, 'filament.app.resources.users.edit', ['record' => $betaDesigner->id]),
            'BLOKER: sahibkar yad studiyanın işçi kartını açdı.',
        );
    }

    /**
     * Seçim (select) siyahıları yad studiyanın sətirlərini TƏKLİF ETMƏMƏLİDİR —
     * əks halda izolyasiya oxuma tərəfində qalır, yazma tərəfində sızır.
     */
    public function test_no_select_dropdown_offers_another_studios_rows(): void
    {
        // Alpha dünyasına yalnız orada rast gəlinən nişanlar qoyuruq.
        app(TenantContext::class)->actingAs($this->alpha->tenant->id, function () {
            $this->alpha->project->forceFill(['name' => 'ALPHAMARKER layihə'])->save();
            $this->alpha->client->forceFill(['name' => 'ALPHAMARKER müştəri'])->save();
            $this->alpha->user('designer')->forceFill(['name' => 'ALPHAMARKER dizayner'])->saveQuietly();
            Supplier::create(['name' => 'ALPHAMARKER təchizatçı']);
            Role::create([
                'tenant_id' => $this->alpha->tenant->id,
                'key' => 'alphamarker_rol',
                'name' => 'ALPHAMARKER rol',
                'levels' => ['projects' => 1],
                'own_projects_only' => false,
                'is_system' => false,
                'active' => true,
            ]);
        });

        $betaOwner = $this->beta->user('owner');

        $pages = [
            'filament.app.resources.tasks.create',
            'filament.app.resources.expenses.create',
            'filament.app.resources.invoices.create',
            'filament.app.resources.purchase-orders.create',
            'filament.app.resources.meetings.create',
            'filament.app.resources.time-entries.create',
            'filament.app.resources.users.create',
            'filament.app.resources.projects.create',
        ];

        foreach ($pages as $route) {
            $this->flushSession();
            $html = $this->actingAs($betaOwner)->get(route($route))->getContent();

            $this->assertStringNotContainsStringQuietly(
                'ALPHAMARKER',
                $html,
                "BLOKER: «{$route}» səhifəsindəki seçim siyahısı alpha studiyasının sətirlərini təklif edir.",
            );

            while (SupportRedirects::$redirectorCacheStack !== []) {
                app()->instance('redirect', array_pop(SupportRedirects::$redirectorCacheStack));
            }
        }
    }

    /**
     * Filament cədvəl axtarışı scope-dan yan keçmir: beta sahibkarı alpha
     * layihəsinin adını yazsa da nəticə boş olmalıdır.
     */
    public function test_table_search_cannot_reach_another_studios_rows(): void
    {
        app(TenantContext::class)->actingAs(
            $this->alpha->tenant->id,
            fn () => $this->alpha->task->forceFill(['title' => 'ALPHAMARKER tapşırığı'])->save(),
        );

        // Axtarış/sıralama parametrləri URL-də daşınır (`#[Url]`), yəni hücumçu
        // Livewire-ə toxunmadan sadəcə linki yazır. Ona görə HTTP səviyyəsində
        // yoxlanılır.
        $this->flushSession();
        $html = $this->actingAs($this->beta->user('owner'))
            ->get(route('filament.app.resources.tasks.index').'?tableSearch=ALPHAMARKER&tableSortColumn=title&tableSortDirection=asc')
            ->getContent();

        $this->assertStringNotContainsStringQuietly(
            'ALPHAMARKER',
            $html,
            'BLOKER: cədvəl axtarışı başqa studiyanın tapşırığını tapdı.',
        );
    }

    /**
     * Relation manager-lər (smeta, ödənişlər, sənədlər, fayllar, üzvlər...)
     * VALİDEYN layihənin səhifəsində yaşayır. Ona görə onların izolyasiyası
     * valideyn qapısından gəlir: yad studiyanın layihə səhifəsi ümumiyyətlə
     * açılmamalıdır — açılsaydı, 16 relation manager-in hamısı birdən sızardı.
     */
    public function test_the_parent_gate_closes_every_relation_manager(): void
    {
        $betaOwner = $this->beta->user('owner');

        $this->assertNotSame(
            200,
            $this->statusFor($betaOwner, 'filament.app.resources.projects.edit', ['record' => $this->alpha->project->id]),
            'BLOKER: beta sahibkarı alpha layihəsinin səhifəsini (və bütün relation manager-lərini) açdı.',
        );

        $this->assertNotSame(
            200,
            $this->statusFor($betaOwner, 'filament.app.resources.clients.edit', ['record' => $this->alpha->client->id]),
            'BLOKER: beta sahibkarı alpha müştərisinin kartını (portal hesabları relation manager-i ilə) açdı.',
        );

        // Valideyn modelin özü də sorğuda görünməməlidir.
        $this->assertFalse($betaOwner->can('view', $this->alpha->project));
        $this->assertFalse($betaOwner->can('view', $this->alpha->client));
    }

    // =====================================================================
    // 7. «YALNIZ ÖZ LAYİHƏLƏRİ» (OWN_PROJECTS_ONLY)
    // =====================================================================

    /**
     * Dizayner alpha-nın ƏSAS layihəsinin üzvüdür, İKİNCİ layihənin deyil.
     * İkinci layihənin HEÇ BİR qeydi ona açıq olmamalıdır — modul-modul.
     */
    public function test_own_projects_only_holds_across_every_module(): void
    {
        $designer = $this->alpha->user('designer');
        $other = $this->alpha->otherProject;

        [$stage, $task, $file, $budgetLine, $payment, $document, $procurement, $approval]
            = $this->buildForeignProjectRecords($other);

        $this->assertTrue(AccessMatrix::requiresOwnProject($designer), 'Dizayner «yalnız öz layihələri» rejimində deyil.');

        // Öz layihəsi açıqdır (əks halda test heç nə sübut etmir).
        $this->assertTrue($designer->can('view', $this->alpha->project));
        $this->assertTrue($designer->can('view', $this->alpha->task));

        // Yad layihə və onun bütün qeydləri bağlıdır.
        $denied = [
            'project' => $other,
            'stage' => $stage,
            'task' => $task,
            'file' => $file,
            'budget line' => $budgetLine,
            'payment' => $payment,
            'document' => $document,
            'procurement item' => $procurement,
            'approval' => $approval,
        ];

        foreach ($denied as $label => $record) {
            $this->assertFalse(
                $designer->can('view', $record),
                "Dizayner üzvü OLMADIĞI layihənin «{$label}» qeydini görür.",
            );
            $this->assertFalse(
                $designer->can('update', $record),
                "Dizayner üzvü OLMADIĞI layihənin «{$label}» qeydini redaktə edə bilir.",
            );
        }

        // Səhifə səthləri: planlayıcı, «Diqqət tələb edir», tapşırıq siyahısı.
        foreach (['task-planner', 'attention'] as $page) {
            $this->flushSession();
            $html = $this->actingAs($designer)->get(route("filament.app.pages.{$page}"))->getContent();

            $this->assertStringNotContainsStringQuietly(
                'YADLAYIHE-TAPSIRIGI',
                $html,
                "«{$page}» səhifəsi yad layihənin tapşırığını göstərir.",
            );
        }

        // Təqvim feed-i: yad layihənin mərhələsi/ödənişi düşməməlidir.
        $this->flushSession();
        $events = $this->actingAs($designer)->getJson(route('calendar.events', [
            'start' => now()->subMonth()->toDateString(),
            'end' => now()->addYear()->toDateString(),
        ]))->json();

        $titles = implode(' | ', array_column($events, 'title'));

        $this->assertStringNotContainsStringQuietly('YADLAYIHE', $titles, 'Təqvim feed-i yad layihənin hadisəsini göstərir.');
    }

    /**
     * Sahibkar və mühasib isə BÜTÜN layihələri görür — «öz layihələri» şərti
     * onlara aid deyil (TZ §5.4). Əks halda büro öz məlumatını itirər.
     */
    public function test_owner_and_accountant_are_not_limited_to_own_projects(): void
    {
        foreach (['owner', 'accountant'] as $role) {
            $user = $this->alpha->user($role);

            $this->assertFalse(
                AccessMatrix::requiresOwnProject($user),
                "[{$role}] «yalnız öz layihələri» rejiminə düşdü — büro öz layihələrini görməyəcək.",
            );
            $this->assertTrue($user->can('view', $this->alpha->otherProject), "[{$role}] studiyanın ikinci layihəsini görmür.");
        }
    }

    /**
     * @return array{0: Stage, 1: Task, 2: ProjectFile, 3: BudgetLine, 4: Payment, 5: Document, 6: ProcurementItem, 7: Approval}
     */
    private function buildForeignProjectRecords(Project $project): array
    {
        return app(TenantContext::class)->actingAs($this->alpha->tenant->id, function () use ($project) {
            $stage = $project->stages()->create(['name' => 'YADLAYIHE mərhələ', 'position' => 1, 'weight' => 1, 'status' => 'in_progress']);

            $task = Task::create([
                'project_id' => $project->id,
                'stage_id' => $stage->id,
                'title' => 'YADLAYIHE-TAPSIRIGI',
                'status' => 'todo',
                'due_date' => now()->addDays(3),
                'assignee_user_id' => $this->alpha->user('owner')->id,
            ]);

            $file = ProjectFile::create([
                'project_id' => $project->id, 'category' => 'plan', 'visibility' => 'internal',
                'title' => 'YADLAYIHE cizgi', 'file_path' => 'files/yadlayihe.dwg',
            ]);

            $budgetLine = $project->budgetLines()->create([
                'work_type' => 'YADLAYIHE iş', 'unit' => 'm2', 'qty' => 1,
                'work_price' => 555, 'material_price' => 0, 'position' => 9,
            ]);

            $payment = $project->payments()->create([
                'title' => 'YADLAYIHE ödəniş', 'amount' => 777, 'status' => 'pending', 'due_date' => now()->addWeek(),
            ]);

            $document = Document::create([
                'project_id' => $project->id, 'type' => 'other',
                'title' => 'YADLAYIHE sənəd', 'file_path' => 'docs/yadlayihe.pdf', 'visible_to_client' => false,
            ]);

            $procurement = $project->procurementItems()->create([
                'name' => 'YADLAYIHE mal', 'qty' => 1, 'price' => 100, 'purchase_status' => 'planned',
            ]);

            $approval = Approval::create([
                'approvable_type' => 'budget_line',
                'approvable_id' => $budgetLine->id,
                'project_id' => $project->id,
                'requested_by_user_id' => $this->alpha->user('owner')->id,
                'client_user_id' => $this->alpha->secondPortalUser->id,
                'status' => 'pending',
            ]);

            return [$stage, $task, $file, $budgetLine, $payment, $document, $procurement, $approval];
        });
    }

    /** @return array<int, array{0: string}> */
    public static function roleProvider(): array
    {
        return array_map(
            fn (StaffRole $role) => [$role->value],
            StaffRole::cases(),
        );
    }

    /**
     * Resurs → [model, domen, siyahı üçün minimum səviyyə, create üçün minimum
     * səviyyə (null = create səhifəsi yoxdur)].
     *
     * Bu cədvəl TZ §5.4 matrisinin resurs qarşılığıdır. Policy-dən KÖÇÜRÜLMÜR —
     * policy matrisdən ayrılanda test qırılsın deyə əl ilə yazılıb.
     */
    private const RESOURCE_GATES = [
        'clients' => [Client::class, 'clients', 1, 2],
        'leads' => [Lead::class, 'clients', 1, 2],
        'projects' => [Project::class, 'projects', 1, 3],
        'tasks' => [Task::class, 'stages_tasks', 1, 2],
        'time-entries' => [TimeEntry::class, 'stages_tasks', 1, 2],
        'meetings' => [Meeting::class, 'projects', 1, 2],
        'expenses' => [Expense::class, 'payments', 1, 2],
        'invoices' => [Invoice::class, 'payments', 1, 2],
        'purchase-orders' => [PurchaseOrder::class, 'procurement', 1, 2],
        'suppliers' => [Supplier::class, 'procurement', 1, 2],
        'brief-questions' => [BriefQuestion::class, 'brief', 1, null],
        'automation-rules' => [AutomationRule::class, 'owner_dashboard', 3, null],
        'users' => [User::class, 'owner_dashboard', 3, 3],
    ];

    /**
     * Bir sorğu göndərir və HTTP statusunu qaytarır.
     *
     * Niyə ayrı köməkçi: Livewire `SupportRedirects::boot()` konteynerdəki
     * `redirect` bağlamasını ÖZ Redirector-u ilə əvəz edir və onu yalnız
     * `dehydrate()` geri qaytarır. Filament səhifəsi mount-da 403/404 atdıqda
     * `dehydrate()` heç vaxt işləmir, yəni bağlama PROSESDƏ qalır — real mühitdə
     * bu zərərsizdir (proses sorğu ilə bitir), TESTDƏ isə həmin prosesdəki
     * NÖVBƏTİ sorğu `StartSession`-da TypeError ilə 500 verir və icazə testi
     * yalançı nəticə göstərir. Ona görə hər sorğudan sonra orijinal redirector
     * bərpa olunur.
     */
    private function statusFor(User $user, string $route, array $parameters = []): int
    {
        // Panel `AuthenticateSession` işlədir: bir testdə istifadəçi dəyişəndə
        // sessiyada qalan köhnə parol hash-ı uyğunsuzluq yaradır və istifadəçi
        // login səhifəsinə 302 ilə atılır — icazə testi onda yalançı nəticə verir.
        $this->flushSession();

        // `status()` yox, `getStatusCode()`: fayl endirmə `StreamedResponse`
        // qaytarır və onda Laravel-in `status()` köməkçisi yoxdur.
        $status = $this->actingAs($user)->get(route($route, $parameters))->getStatusCode();

        while (SupportRedirects::$redirectorCacheStack !== []) {
            app()->instance('redirect', array_pop(SupportRedirects::$redirectorCacheStack));
        }

        return $status;
    }

    private function matrixAllows(User $user, string $domain, int $level): bool
    {
        return AccessMatrix::level($user, Domain::from($domain))->value >= $level;
    }

    /**
     * TAPINTI [CİDDİ] — sənədləşdirilir: `brief_questions` (eyni şəkildə
     * `brief_sections`, `brief_templates`, `stage_templates`) QLOBAL kataloqdur,
     * amma redaktə səthi HƏR studiyaya açıqdır: matrisdə Brif = Tam olan hər kəs
     * (sahibkar, layihə meneceri, DİZAYNER) variant şəkillərini dəyişə bilir və
     * dəyişiklik BÜTÜN studiyaların brifinə düşür. Bu, oxuma sızması deyil,
     * paylaşılan vəziyyətin korlanmasıdır: bir studiyanın dizayneri digər
     * studiyanın müştərisinin gördüyü şəkli əvəz edə bilər.
     *
     * Düzəliş `brief_questions`-a `tenant_id` əlavə etmək (roles modelindəki
     * copy-on-write) ya da redaktəni platforma admininə buraxmaqdır — hər ikisi
     * brif modulunun miqrasiya/resurs qərarıdır və bu auditin sahəsindən
     * kənardır. Ona görə mövcud davranış burada SÜBUT kimi qeydə alınır.
     */
    public function test_global_brief_question_bank_is_writable_from_every_studio(): void
    {
        // Test bazasında bank boşdur (seeder işləmir) — bir sətir özümüz qururuq.
        $section = BriefSection::create([
            'key' => 'qa2_bolme',
            'name' => ['az' => 'QA2 bölmə'],
            'position' => 1,
            'active' => true,
        ]);

        $question = BriefQuestion::create([
            'brief_section_id' => $section->id,
            'key' => 'qa2_sual',
            'label' => ['az' => 'QA2 sual'],
            'type' => 'image_select',
            'options' => ['items' => []],
            'position' => 1,
            'active' => true,
        ]);

        $this->assertTrue(
            $this->alpha->user('designer')->can('update', $question),
            'Dizaynerin Brif = Tam səviyyəsi qlobal bankı redaktə etməyə icazə verir — davranış dəyişib.',
        );
        $this->assertTrue($this->beta->user('designer')->can('update', $question));

        $question->forceFill(['options' => ['qa2_marker' => 'ALPHADAN-GELEN']])->save();

        $seenByBeta = app(TenantContext::class)->actingAs(
            $this->beta->tenant->id,
            fn () => BriefQuestion::query()->whereKey($question->getKey())->first(),
        );

        $this->assertSame(
            'ALPHADAN-GELEN',
            $seenByBeta->options['qa2_marker'] ?? null,
            'Davranış dəyişib: qlobal bank artıq studiyalar arasında paylaşılmır (tapıntı düzəldilmiş ola bilər).',
        );
    }

    /** @return array<string, class-string<Model>> */
    private function models(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) as $path) {
            $name = basename($path, '.php');
            $class = 'App\\Models\\'.$name;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $models[$name] = $class;
        }

        ksort($models);

        return $models;
    }
}
