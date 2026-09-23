<?php

namespace Tests\Feature\QA;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Filament\Pages\Attention;
use App\Filament\Pages\Calendar;
use App\Filament\Pages\ChatCenter;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Profitability;
use App\Filament\Pages\TaskPlanner;
use App\Filament\Resources\ApprovalResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TranslationResource;
use App\Filament\Widgets\CashForecastWidget;
use App\Filament\Widgets\OwnerStatsOverview;
use App\Filament\Widgets\PortfolioFinanceStats;
use App\Filament\Widgets\ProfitabilityWidget;
use App\Filament\Widgets\UpcomingDeadlinesWidget;
use App\Models\Approval;
use App\Models\AutomationRule;
use App\Models\BriefQuestion;
use App\Models\BudgetLine;
use App\Models\ChangeRequest;
use App\Models\Client;
use App\Models\Deliverable;
use App\Models\DiaryEntry;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\ProjectDecision;
use App\Models\ProjectFile;
use App\Models\PunchListIssue;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SpecificationItem;
use App\Models\Stage;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA — ROLLAR VƏ İCAZƏLƏR (müştəri tələbi: "bir neçə işçi yaradıb onlarla giriş
 * etsinlər, digər tasklar görünürmü test etsinlər").
 *
 * Bu fayl YALNIZ oxuyur və yoxlayır — heç bir tətbiq kodu dəyişmir. Hər tapıntı
 * `// QA TAPINTI:` şərhi ilə işarələnib.
 *
 * TARİXÇƏ: ilkin variantda testlər faktiki (qırıq) davranışı təsbit edirdi ki,
 * düzəliş ediləndə qırmızı olub diqqət çəksinlər. Aşağıdakı səkkiz tapıntı
 * DÜZƏLDİLİB, ona görə həmin testlər artıq YENİ, düzgün davranışı qoruyur —
 * şərhlərdə «— DÜZƏLDİLDİ» qeydi ilə işarələnib:
 *   1) ApprovalResource siyahısı layihə üzvlüyünə görə daraldılır;
 *   2) Dashboard (son layihələr, bugünkü tapşırıqlar, gecikmə sayğacı) kəsilir;
 *   3) beş maliyyə/qrafik vidjeti rol adını yox, AccessMatrix-i oxuyur;
 *   4) Calendar və ChatCenter səhifələrində domen gate-i var;
 *   5) tapşırıq görünürlüyü icraçıdan LAYİHƏ ÜZVLÜYÜNƏ keçib;
 *   6) təyinat özlüyündə üzvlüyü əvəz etmir;
 *   7) `is_active=false` policy qatında da rədd edir;
 *   8) `users.role`-dakı yad sətir SafeStaffRole ilə `null` olur (fail-closed).
 *
 * Hər düzəliş testində İKİ istiqamət yoxlanılır: icazənin bağlandığı VƏ heç bir
 * rolun mövcud girişini itirmədiyi (icazə genişlənməyib/daralmayıb).
 */
class RolePermissionQaTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    /** Matrisdəki bütün rollar. */
    private const ROLES = ['owner', 'project_manager', 'designer', 'visualizer', 'procurement', 'accountant'];

    /**
     * Hər domen üçün həmin domeni qoruyan policy-lərin nümayəndələri.
     * `analytics` domeninin MODEL policy-si yoxdur — yalnız səhifə gate-ləri var.
     *
     * @var array<string, array<int, class-string>>
     */
    private const DOMAIN_MODELS = [
        Domain::Clients->value => [Client::class, Lead::class],
        Domain::Projects->value => [Project::class, ChangeRequest::class, ProjectDecision::class, Meeting::class],
        Domain::Brief->value => [BriefQuestion::class],
        Domain::StagesTasks->value => [Task::class, Stage::class, TimeEntry::class, PunchListIssue::class],
        Domain::FilesDocuments->value => [Document::class, ProjectFile::class, Deliverable::class, DiaryEntry::class],
        Domain::Budget->value => [BudgetLine::class],
        Domain::Procurement->value => [ProcurementItem::class, PurchaseOrder::class, Supplier::class, SpecificationItem::class],
        Domain::Payments->value => [Payment::class, Invoice::class, Expense::class],
        Domain::OwnerDashboard->value => [AutomationRule::class, User::class],
        Domain::Analytics->value => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        AccessMatrix::flushCache();
        $this->studio = StudioWorld::make('rolqa');
    }

    protected function tearDown(): void
    {
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // 1. MATRİS ↔ POLICY UYĞUNLUĞU (6 rol × 10 domen = 60 xana)
    // ---------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function matrixCells(): array
    {
        $cases = [];

        foreach (self::ROLES as $role) {
            foreach (Domain::cases() as $domain) {
                $cases["{$role} × {$domain->value}"] = [$role, $domain->value];
            }
        }

        return $cases;
    }

    /**
     * TƏHLÜKƏSİZLİK İSTİQAMƏTİ (sərt): matris «Yoxdur» deyirsə, həmin domenin
     * BÜTÜN policy-ləri `viewAny`, `create` üçün `false` qaytarmalıdır.
     * Matris nəyisə verirsə, policy heç olmasa bir nümayəndədə oxumağa icazə
     * verməlidir (modul ümumiyyətlə açılırmı).
     */
    #[DataProvider('matrixCells')]
    public function test_matrix_cell_matches_policies(string $role, string $domainValue): void
    {
        $domain = Domain::from($domainValue);
        $user = $this->studio->user($role);
        $level = AccessMatrix::level($user, $domain);
        $models = self::DOMAIN_MODELS[$domainValue];

        if ($models === []) {
            // QA TAPINTI: `analytics` domeni üçün heç bir model policy-si yoxdur —
            // yalnız Attention/Profitability səhifə gate-ləri qoruyur.
            $this->assertSame([], $models);

            return;
        }

        if ($level === AccessLevel::None) {
            foreach ($models as $model) {
                $this->assertFalse(
                    $user->can('viewAny', $model),
                    "MATRİS POZUNTUSU: «{$role}» rolunun «{$domainValue}» domeni Yoxdur-dur, amma {$model}::viewAny icazə verir.",
                );
                $this->assertFalse(
                    $user->can('create', $model),
                    "MATRİS POZUNTUSU: «{$role}» rolunun «{$domainValue}» domeni Yoxdur-dur, amma {$model}::create icazə verir.",
                );
            }

            return;
        }

        $anyReadable = false;
        foreach ($models as $model) {
            $anyReadable = $anyReadable || $user->can('viewAny', $model);
        }

        // QA TAPINTI [KİÇİK]: mühasibin matrisdə «Rəhbər paneli = Baxış» xanası
        // var, lakin bu domeni qoruyan hər iki policy (UserPolicy:113,
        // AutomationRulePolicy:14) TAM tələb edir — yəni «Baxış» səviyyəsinin
        // heç bir policy qarşılığı yoxdur, xana ölüdür.
        if ($role === 'accountant' && $domainValue === Domain::OwnerDashboard->value) {
            $this->assertFalse(
                $anyReadable,
                'Bu test qırmızıdırsa Rəhbər paneli = Baxış xanasına policy qarşılığı əlavə olunub — tapıntı bağlanıb.',
            );

            return;
        }

        $this->assertTrue(
            $anyReadable,
            "MATRİS POZUNTUSU: «{$role}» rolunun «{$domainValue}» domeni ≥Baxış-dır, amma bu domenin heç bir policy-si oxumağa icazə vermir.",
        );
    }

    /**
     * 6×10 matrisin TAM xəritəsi. Hər xana üçün "matris səviyyəsi" ilə policy-nin
     * faktiki qaytardığı müqayisə olunur; matrisdən SƏRT olan xanalar
     * (matris icazə verir, policy vermir) aşağıdakı siyahıda qeydə alınıb.
     * Siyahı dəyişsə test qırmızı olur — yəni matris-policy driftinin qoruyucusu.
     */
    public function test_full_matrix_map_has_no_unknown_drift(): void
    {
        $stricter = [];
        $looser = [];

        foreach (self::ROLES as $role) {
            $user = $this->studio->user($role);

            foreach (Domain::cases() as $domain) {
                $level = AccessMatrix::level($user, $domain);

                foreach (self::DOMAIN_MODELS[$domain->value] as $model) {
                    $short = class_basename($model);

                    $read = $user->can('viewAny', $model);
                    $expectedRead = $level->atLeast(AccessLevel::View);
                    if ($read !== $expectedRead) {
                        if ($read) {
                            $looser[] = "{$role}|{$domain->value}|{$short}|viewAny";
                        } else {
                            $stricter[] = "{$role}|{$domain->value}|{$short}|viewAny";
                        }
                    }

                    $write = $user->can('create', $model);
                    $expectedWrite = $level->atLeast(AccessLevel::Edit);
                    if ($write !== $expectedWrite) {
                        if ($write) {
                            $looser[] = "{$role}|{$domain->value}|{$short}|create";
                        } else {
                            $stricter[] = "{$role}|{$domain->value}|{$short}|create";
                        }
                    }
                }
            }
        }

        sort($stricter);
        sort($looser);

        // QA TAPINTI [KİÇİK]: matrisdən SƏRT olan xanalar. Hamısı qəsdəndir
        // (kritik əməliyyatlar üçün bir pillə yüksək tələb), amma sənəddə
        // «matris = həqiqət» deyilir — sənəd/kod uyğunsuzluğu kimi qeyd olunur.
        $this->assertSame(
            self::EXPECTED_STRICTER,
            $stricter,
            'Matrisdən sərt olan xanaların siyahısı dəyişib — policy ilə matris arasında yeni drift var.',
        );

        // Matrisdən GENİŞ (policy matrisdən artıq icazə verir) — burada BOŞ olmalıdır.
        $this->assertSame(
            [],
            $looser,
            'TƏHLÜKƏSİZLİK: policy matrisin verdiyindən ARTIQ icazə verir — '.implode(', ', $looser),
        );
    }

    /** @var array<int, string> */
    private const EXPECTED_STRICTER = [
        'accountant|owner_dashboard|AutomationRule|viewAny',
        'accountant|owner_dashboard|User|viewAny',
        // Brif sualları git-dəki qlobal bankdan gəlir — paneldən yaratmaq qəsdən bağlıdır.
        'designer|brief|BriefQuestion|create',
        // Layihə yaratmaq matrisdə Redaktə yox, TAM tələb edir (ProjectPolicy:26).
        'designer|projects|Project|create',
        'owner|brief|BriefQuestion|create',
        // Avtomatlaşdırma qaydaları yalnız seeder-dən gəlir (AutomationRulePolicy:26).
        'owner|owner_dashboard|AutomationRule|create',
        'procurement|projects|Project|create',
        'project_manager|brief|BriefQuestion|create',
        'visualizer|projects|Project|create',
    ];

    // ---------------------------------------------------------------------
    // 2. «YALNIZ ÖZ LAYİHƏSİ» QAYDASI
    // ---------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool}> */
    public static function ownProjectRoles(): array
    {
        return [
            'owner (bütün layihələr)' => ['owner', true],
            'project_manager (yalnız öz)' => ['project_manager', false],
            'designer (yalnız öz)' => ['designer', false],
            'visualizer (yalnız öz)' => ['visualizer', false],
            'procurement (yalnız öz)' => ['procurement', false],
            'accountant (bütün layihələr)' => ['accountant', true],
        ];
    }

    #[DataProvider('ownProjectRoles')]
    public function test_other_project_visibility_follows_membership(string $role, bool $seesEverything): void
    {
        $user = $this->studio->user($role);
        $other = $this->studio->otherProject;

        // Layihə səviyyəsi
        $this->assertSame(
            $seesEverything && AccessMatrix::allows($user, Domain::Projects, AccessLevel::View),
            $user->can('view', $other),
            "«{$role}» üzv olmadığı layihəni görmə qaydasını pozur.",
        );
    }

    public function test_other_project_records_are_hidden_from_non_members(): void
    {
        [$stage, $task, $file, $budgetLine, $payment] = $this->buildOtherProjectRecords();

        foreach (['project_manager', 'designer', 'visualizer', 'procurement'] as $role) {
            $user = $this->studio->user($role);

            $this->assertFalse($user->can('view', $stage), "«{$role}» yad layihənin MƏRHƏLƏsini görür.");
            $this->assertFalse($user->can('view', $file), "«{$role}» yad layihənin FAYLını görür.");
            $this->assertFalse($user->can('view', $budgetLine), "«{$role}» yad layihənin BÜDCƏ sətrini görür.");
            $this->assertFalse($user->can('view', $payment), "«{$role}» yad layihənin ÖDƏNİŞini görür.");
            $this->assertFalse($user->can('view', $task), "«{$role}» yad layihənin TAPŞIRIĞINI görür.");
        }
    }

    // ---------------------------------------------------------------------
    // 3. TAPŞIRIQ GÖRÜNÜRLÜYÜ (müştərinin konkret tələbi)
    // ---------------------------------------------------------------------

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ. Əvvəl tapşırıq görünürlüyü LAYİHƏ
     * ÜZVLÜYÜNƏ yox, yalnız TƏYİNATÇIYA (`assignee_user_id`) bağlı idi
     * (TaskPolicy + TaskResource::getEloquentQuery).
     *
     * İndi hədd LAYİHƏ ÜZVLÜYÜdür (`TaskPolicy::belongsToVisibleProject()`,
     * `TaskResource::scopeToVisibleProjects()`), yəni bu test artıq tapıntını
     * yox, DÜZGÜN davranışı qoruyur.
     *
     * Nəticə (a): layihə meneceri ÖZ layihəsinin, başqasına təyin edilmiş
     * tapşırığını da görür — matrisdəki Mərhələ/Tapşırıq = Tam nəhayət işləyir.
     */
    public function test_project_manager_sees_every_task_in_their_own_project(): void
    {
        $pm = $this->studio->user('project_manager');
        $task = $this->studio->task; // öz layihəsi, dizaynerə təyin edilib

        $this->assertTrue($pm->can('view', $this->studio->project), 'PM öz layihəsini görməlidir.');
        $this->assertTrue(
            AccessMatrix::allows($pm, Domain::StagesTasks, AccessLevel::Full),
            'Matris PM-ə Mərhələ/Tapşırıq = Tam verir.',
        );
        $this->assertNotSame($pm->id, $task->assignee_user_id, 'Tapşırıq qəsdən PM-ə təyin edilməyib.');

        // DÜZƏLDİLDİ: təyinatçı olmasa da, layihə üzvü olduğu üçün açıqdır.
        $this->assertTrue(
            $pm->can('view', $task),
            'PM öz layihəsinin tapşırığını görə bilmir — TaskPolicy yenidən icraçıya bağlanıb.',
        );
        $this->assertTrue($pm->can('update', $task));

        // İCAZƏ GENİŞLƏNMƏYİB: üzvü olmadığı layihənin tapşırığı hələ də bağlıdır.
        [, $foreignTask] = $this->buildOtherProjectRecords();

        $this->assertFalse(
            $pm->can('view', $foreignTask),
            'TƏHLÜKƏSİZLİK: PM üzvü olmadığı layihənin tapşırığını görür.',
        );

        $html = $this->actingAs($pm)->get(route('filament.app.resources.tasks.index'))->getContent();

        $this->assertStringContainsStringQuietly(
            $this->studio->task->title,
            $html,
            'Tapşırıqlar siyahısında PM öz layihəsinin tapşırığını görmür.',
        );
        $this->assertStringNotContainsStringQuietly(
            $foreignTask->title,
            $html,
            'SIZMA: tapşırıqlar siyahısında yad layihənin tapşırığı görünür.',
        );
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ. Əvvəl TƏYİNAT üzvlüyü ƏVƏZ EDİRDİ: üzvü
     * OLMADIĞI layihənin tapşırığı kiməsə təyin ediləndə o adam layihəni aça
     * bilmədiyi halda tapşırığı görür və REDAKTƏ edirdi.
     *
     * İndi təyinat özlüyündə heç nə vermir — `TaskPolicy::belongsToVisibleProject()`
     * layihə üzvlüyünü tələb edir, `TaskResource` sorğusu da eyni şərtlə kəsilir.
     */
    public function test_assignment_alone_no_longer_grants_task_access_outside_own_projects(): void
    {
        $designer = $this->studio->user('designer');

        [$foreignStage] = $this->buildOtherProjectRecords();

        $foreignTask = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => Task::create([
            'project_id' => $this->studio->otherProject->id,
            'stage_id' => $foreignStage->id,
            'title' => 'YAD-LAYIHE-TEYINATI',
            'status' => 'todo',
            'assignee_user_id' => $designer->id,
        ]));

        $this->assertFalse(
            $designer->can('view', $this->studio->otherProject),
            'Dizayner üzv olmadığı layihəni aça bilməməlidir.',
        );

        // DÜZƏLDİLDİ: layihə bağlıdırsa, onun tapşırığı da bağlıdır.
        $this->assertFalse(
            $designer->can('view', $foreignTask),
            'TƏHLÜKƏSİZLİK: təyinat hələ də üzvlüyü əvəz edir — yad layihənin tapşırığı açıqdır.',
        );
        $this->assertFalse($designer->can('update', $foreignTask));
        $this->assertFalse($designer->can('delete', $foreignTask));

        $html = $this->actingAs($designer)->get(route('filament.app.resources.tasks.index'))->getContent();

        $this->assertStringNotContainsStringQuietly(
            $foreignTask->title,
            $html,
            'SIZMA: yad layihənin (özünə təyin edilmiş) tapşırığı siyahıda görünür.',
        );

        // İCAZƏ DARALMAYIB: öz layihəsinin tapşırığı həm policy, həm siyahı üçün açıqdır.
        $this->assertTrue($designer->can('view', $this->studio->task), 'Dizayner öz layihəsinin tapşırığını itirməməlidir.');
        $this->assertStringContainsStringQuietly(
            $this->studio->task->title,
            $html,
            'Dizayner öz layihəsinin tapşırığını siyahıda görməlidir.',
        );
    }

    // ---------------------------------------------------------------------
    // 4. NAVİQASİYA ≠ QORUNMA — birbaşa URL sınaqları
    // ---------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function directUrlAccess(): array
    {
        $pages = [
            'tasks' => ['filament.app.resources.tasks.index', ['owner', 'project_manager', 'designer', 'visualizer', 'procurement', 'accountant']],
            'task-planner' => ['filament.app.pages.task-planner', ['owner', 'project_manager', 'designer', 'visualizer', 'procurement', 'accountant']],
            'projects' => ['filament.app.resources.projects.index', ['owner', 'project_manager', 'designer', 'visualizer', 'procurement', 'accountant']],
            'brief-questions' => ['filament.app.resources.brief-questions.index', ['owner', 'project_manager', 'designer', 'visualizer', 'procurement']],
            'time-entries' => ['filament.app.resources.time-entries.index', ['owner', 'project_manager', 'designer', 'visualizer', 'procurement', 'accountant']],
            'meetings' => ['filament.app.resources.meetings.index', ['owner', 'project_manager', 'designer', 'visualizer', 'procurement', 'accountant']],
            'automation-rules' => ['filament.app.resources.automation-rules.index', ['owner']],
            'users' => ['filament.app.resources.users.index', ['owner']],
            'translations' => ['filament.app.resources.translations.index', []],
            'attention' => ['filament.app.pages.attention', ['owner', 'project_manager', 'accountant']],
            // Razılaşdırma siyahısı: Smeta VƏ YA Komplektasiya oxuma hüququ tələb edir.
            'approvals' => ['filament.app.resources.approvals.index', ['owner', 'project_manager', 'designer', 'procurement', 'accountant']],
        ];

        $cases = [];
        foreach ($pages as $page => [$route, $allowed]) {
            foreach (self::ROLES as $role) {
                $cases["{$role} → {$page}"] = [$route, $role, in_array($role, $allowed, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('directUrlAccess')]
    public function test_direct_url_is_enforced_server_side(string $route, string $role, bool $allowed): void
    {
        $status = $this->actingAs($this->studio->user($role))->get(route($route))->status();

        if ($allowed) {
            $this->assertSame(200, $status, "«{$role}» icazəsi olduğu halda {$route} açıla bilmədi (status {$status}).");

            return;
        }

        $this->assertNotSame(500, $status, "«{$role}» {$route} ünvanında 500 aldı — rədd 403 olmalıdır.");
        $this->assertContains($status, [403, 404], "NAVİQASİYA ≠ QORUNMA: «{$role}» birbaşa URL ilə {$route} səhifəsini açdı (status {$status}).");
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ. Əvvəl `Calendar` və `ChatCenter`
     * səhifələrində heç bir `canAccess()` yox idi və bütün domenləri «Yoxdur»
     * olan xüsusi rol da bu ekranları 200 ilə açırdı (TZ §5.20 pozuntusu).
     *
     * İndi hər ikisində domen gate-i var:
     *   Calendar   → Mərhələ/Tapşırıq ≥ Baxış (təqvimin onurğası mərhələ plan
     *                tarixləri və tapşırıq son tarixləridir);
     *   ChatCenter → Layihələr ≥ Baxış (çat layihə danışığıdır).
     * Standart altı rolun hamısında hər iki domen ən azı Baxış səviyyəsindədir,
     * ona görə heç kim mövcud girişini İTİRMİR — test bunu da yoxlayır.
     */
    public function test_calendar_and_chat_center_are_gated_by_domain(): void
    {
        // İCAZƏ DARALMAYIB: standart altı rolun hamısı hər iki ekranı saxlayır.
        // (Bir test daxilində istifadəçi dəyişdikcə sessiya sıfırlanır, əks halda
        // Filament köhnə sessiyaya görə 302 qaytarır — icazə ilə əlaqəsi yoxdur.)
        foreach (self::ROLES as $role) {
            $user = $this->studio->user($role);

            $this->flushSession();
            $this->assertSame(
                200,
                $this->actingAs($user)->get(route('filament.app.pages.calendar'))->status(),
                "REQRESSİYA: «{$role}» Təqvim səhifəsini itirdi.",
            );

            $this->flushSession();
            $this->assertSame(
                200,
                $this->actingAs($user)->get(route('filament.app.pages.chat-center'))->status(),
                "REQRESSİYA: «{$role}» Çat səhifəsini itirdi.",
            );
        }

        $nobody = $this->userWithCustomRole('hec_ne', [], false);

        $this->assertFalse(AccessMatrix::allows($nobody, Domain::Projects, AccessLevel::View));
        $this->assertFalse(AccessMatrix::allows($nobody, Domain::StagesTasks, AccessLevel::View));

        $this->actingAs($nobody);

        $this->assertFalse(Calendar::canAccess(), 'Təqvim səhifəsi boş matrisli rola açıqdır.');
        $this->assertFalse(ChatCenter::canAccess(), 'Çat səhifəsi boş matrisli rola açıqdır.');

        $this->flushSession();
        $this->assertContains(
            $this->actingAs($nobody)->get(route('filament.app.pages.calendar'))->status(),
            [403, 404],
            'NAVİQASİYA ≠ QORUNMA: boş matrisli rol Təqvim səhifəsini birbaşa URL ilə açdı.',
        );

        $this->flushSession();
        $this->assertContains(
            $this->actingAs($nobody->fresh())->get(route('filament.app.pages.chat-center'))->status(),
            [403, 404],
            'NAVİQASİYA ≠ QORUNMA: boş matrisli rol Çat səhifəsini birbaşa URL ilə açdı.',
        );
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ. Əvvəl `ApprovalResource::getEloquentQuery()`
     * layihə üzvlüyünə görə daraldılmırdı və «yalnız öz layihəsi» rolları
     * (dizayner, komplektləşdirici, PM) razılaşdırma siyahısında üzv olmadıqları
     * layihələrin sətirlərini — yəni smeta başlığı və məbləği — görürdü.
     * `view()` policy-si sətri düzgün bağlayırdı, siyahı isə policy-dən keçmir.
     *
     * İndi resurs digər layihə-əsaslı resurslardakı (Expense, Invoice, Meeting,
     * PurchaseOrder) EYNİ `requiresOwnProject` filtrini tətbiq edir: menecer ya
     * üzv olmadığın layihənin razılaşdırması sorğuya düşmür.
     */
    public function test_approval_list_is_scoped_to_own_projects(): void
    {
        $this->buildOtherProjectRecords();

        $foreignBudget = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => $this->studio->otherProject
            ->budgetLines()
            ->create([
                'work_type' => 'YAD-LAYIHE-SETRI', 'unit' => 'm2', 'qty' => 1,
                'work_price' => 111111, 'material_price' => 0, 'position' => 1,
            ]));

        $foreignApproval = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => Approval::create([
            'approvable_type' => 'budget_line',
            'approvable_id' => $foreignBudget->id,
            'project_id' => $this->studio->otherProject->id,
            'requested_by_user_id' => $this->studio->user('owner')->id,
            'client_user_id' => $this->studio->secondPortalUser->id,
            'status' => 'pending',
        ]));

        $designer = $this->studio->user('designer');

        // Policy sətir səviyyəsində DÜZGÜN bağlayır:
        $this->assertFalse(
            $designer->can('view', $foreignApproval),
            'ApprovalPolicy yad layihənin razılaşdırmasını bağlamalıdır.',
        );

        // DÜZƏLDİLDİ: siyahı sorğusu da eyni şərtlə kəsilir.
        $this->actingAs($designer);

        $ids = ApprovalResource::getEloquentQuery()->pluck('id')->all();

        $this->assertNotContains(
            $foreignApproval->id,
            $ids,
            'SIZMA: ApprovalResource sorğusu yad layihənin razılaşdırmasını hələ də qaytarır.',
        );

        // İCAZƏ DARALMAYIB: üzvü olduğu layihənin razılaşdırması yerindədir.
        $this->assertContains(
            $this->studio->approval->id,
            $ids,
            'Dizayner öz layihəsinin razılaşdırmasını itirməməlidir.',
        );

        $visible = $this->actingAs($designer)
            ->get(route('filament.app.resources.approvals.index'))->getContent();

        // Sətrin «Obyekt» sütunu smeta sətrinin adını açır (Approval::subjectLabel()) —
        // layihə adı cədvəl filtri üçün onsuz da səhifədədir, ona görə yoxlama
        // məhz SƏTRİN məzmunu üzərindədir.
        $this->assertStringNotContainsStringQuietly(
            'YAD-LAYIHE-SETRI',
            $visible,
            'SIZMA: yad layihənin razılaşdırma sətri HTML cavabında görünür.',
        );

        $this->assertStringContainsStringQuietly(
            $this->studio->budgetLine->work_type,
            $visible,
            'Dizayner öz layihəsinin razılaşdırma sətrini siyahıda görməlidir.',
        );

        // Bütün layihələri görən rollar (sahibkar, mühasib) heç nə itirmir.
        foreach (['owner', 'accountant'] as $role) {
            $this->actingAs($this->studio->user($role));

            $this->assertContains(
                $foreignApproval->id,
                ApprovalResource::getEloquentQuery()->pluck('id')->all(),
                "REQRESSİYA: «{$role}» bütün layihələri görməlidir, amma yad razılaşdırmanı itirdi.",
            );
        }
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ. Əvvəl `Dashboard::recentProjects()` və
     * `todayTasks()` sorğuları «yalnız öz layihəsi» qaydasını tətbiq etmirdi və
     * vizualizator/dizayner idarəetmə panelində üzv olmadığı layihənin ADINI,
     * müştərisini və həmin layihənin tapşırıq BAŞLIQLARINI görürdü. Gecikmə
     * sayğacı da bütün studiyanı sayırdı.
     *
     * İndi hər üçü `Dashboard::accessibleProjectIds()` ilə kəsilir (Attention
     * ekranı ilə eyni məntiq); sahibkar və mühasib üçün (own=false) məhdudiyyət
     * yoxdur — test hər iki istiqaməti yoxlayır.
     */
    public function test_dashboard_is_scoped_to_own_projects(): void
    {
        [, $foreignTask] = $this->buildOtherProjectRecords();

        $foreignTask->forceFill(['deadline' => today()])->save();
        $this->studio->task->forceFill(['deadline' => today()])->save();

        $viz = $this->studio->user('visualizer');
        $this->actingAs($viz);

        $this->assertFalse($viz->can('view', $this->studio->otherProject), 'Vizualizator yad layihəni aça bilməməlidir.');
        $this->assertFalse($viz->can('view', $foreignTask), 'Vizualizator yad tapşırığı görə bilməməlidir.');

        $dashboard = new Dashboard;

        $this->assertFalse(
            $dashboard->recentProjects()->contains('id', $this->studio->otherProject->id),
            'SIZMA: Dashboard::recentProjects() hələ də yad layihəni sadalayır.',
        );
        $this->assertFalse(
            $dashboard->todayTasks()->contains('id', $foreignTask->id),
            'SIZMA: Dashboard::todayTasks() hələ də yad layihənin tapşırığını sadalayır.',
        );

        // Vizualizator bu studiyada HEÇ BİR layihənin üzvü deyil — paneli tamam boşdur.
        $this->assertCount(0, $dashboard->recentProjects());
        $this->assertCount(0, $dashboard->todayTasks());

        $html = $this->actingAs($viz)
            ->get(route('filament.app.pages.dashboard'))->getContent();

        $this->assertStringNotContainsStringQuietly(
            $this->studio->otherProject->name,
            $html,
            'SIZMA: idarəetmə panelində yad layihənin adı görünür.',
        );

        $this->assertStringNotContainsStringQuietly(
            $foreignTask->title,
            $html,
            'SIZMA: idarəetmə panelində yad layihənin tapşırıq başlığı görünür.',
        );

        // İCAZƏ DARALMAYIB (a): üzv olan rol öz layihəsini və tapşırığını görür.
        $designer = $this->studio->user('designer');
        $this->actingAs($designer);

        $designerDashboard = new Dashboard;

        $this->assertTrue(
            $designerDashboard->recentProjects()->contains('id', $this->studio->project->id),
            'Dizayner öz layihəsini idarəetmə panelində itirməməlidir.',
        );
        $this->assertTrue(
            $designerDashboard->todayTasks()->contains('id', $this->studio->task->id),
            'Dizayner öz layihəsinin bugünkü tapşırığını itirməməlidir.',
        );
        $this->assertFalse(
            $designerDashboard->recentProjects()->contains('id', $this->studio->otherProject->id),
            'SIZMA: dizayner panelində yad layihə var.',
        );

        // İCAZƏ DARALMAYIB (b): sahibkar bütün studiyanı görməyə davam edir.
        $this->actingAs($this->studio->user('owner'));

        $ownerDashboard = new Dashboard;

        $this->assertTrue(
            $ownerDashboard->recentProjects()->contains('id', $this->studio->otherProject->id),
            'REQRESSİYA: sahibkar bütün layihələri görməlidir.',
        );
        $this->assertTrue(
            $ownerDashboard->todayTasks()->contains('id', $foreignTask->id),
            'REQRESSİYA: sahibkar bütün bugünkü tapşırıqları görməlidir.',
        );
    }

    // ---------------------------------------------------------------------
    // 5. VİDJETLƏR — pul rəqəmi sızırmı
    // ---------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function moneyBlindRoles(): array
    {
        return ['designer' => ['designer'], 'visualizer' => ['visualizer'], 'procurement' => ['procurement']];
    }

    #[DataProvider('moneyBlindRoles')]
    public function test_finance_widgets_are_hidden_from_roles_without_payments(string $role): void
    {
        $user = $this->studio->user($role);
        $this->actingAs($user);

        $this->assertFalse(AccessMatrix::allows($user, Domain::Payments, AccessLevel::View));

        foreach ([OwnerStatsOverview::class, PortfolioFinanceStats::class, CashForecastWidget::class, ProfitabilityWidget::class] as $widget) {
            $this->assertFalse(
                $widget::canView(),
                "PUL SIZMASI: «{$role}» rolu üçün {$widget} vidjeti göstərilir, halbuki Ödənişlər = Yoxdur.",
            );
        }
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ. Əvvəl maliyyə vidjetləri matrisə yox,
     * SABİT rol adına baxırdı (`isOwner()` / `StaffRole::Accountant`), yəni rol
     * konstruktoru bu beş ekranda ölü idi və iki tərəfə də səhv cavab verirdi.
     *
     * İndi hər vidjet `AccessMatrix`-i oxuyur:
     *   PortfolioFinanceStats / CashForecastWidget / ProfitabilityWidget
     *       → Analitika ≥ Baxış VƏ Ödənişlər = Tam (Profitability səhifəsi ilə
     *         eyni şərt — səhifə açılıb vidjet boş qalmasın);
     *   OwnerStatsOverview   → Rəhbər paneli ≥ Baxış;
     *   UpcomingDeadlinesWidget → Mərhələ/Tapşırıq = Tam (üstəgəl sətirlər
     *         layihə üzvlüyünə görə süzülür).
     *
     * Nəticə: bloklanmış xüsusi rol vidjetləri İTİRİR, səlahiyyət verilmiş
     * xüsusi rol isə QAZANIR.
     */
    public function test_finance_widgets_follow_the_custom_role_matrix(): void
    {
        $this->seed(RoleSeeder::class);

        $blocked = Role::create([
            'tenant_id' => $this->studio->tenant->id,
            'key' => 'arxiv_ishcisi',
            'name' => 'Arxiv işçisi',
            'levels' => [Domain::Projects->value => AccessLevel::View->value],
            'own_projects_only' => true,
            'is_system' => false,
            'active' => true,
        ]);

        // `role` sütunu hələ `accountant`-dır, amma xüsusi rol hər şeyi bağlayır.
        $user = $this->studio->user('accountant');
        $user->forceFill(['role_id' => $blocked->id])->save();
        AccessMatrix::flushCache();

        $user = $user->fresh();
        $this->actingAs($user);

        $this->assertFalse(
            AccessMatrix::allows($user, Domain::Payments, AccessLevel::View),
            'Xüsusi rol Ödənişlər domenini bağlayır.',
        );
        $this->assertFalse(
            AccessMatrix::allows($user, Domain::OwnerDashboard, AccessLevel::View),
            'Xüsusi rol Rəhbər paneli domenini bağlayır.',
        );

        foreach ([PortfolioFinanceStats::class, CashForecastWidget::class, ProfitabilityWidget::class, OwnerStatsOverview::class, UpcomingDeadlinesWidget::class] as $widget) {
            $this->assertFalse(
                $widget::canView(),
                "PUL SIZMASI: {$widget} hələ də `role` sütununa baxır — bloklanmış xüsusi rol onu görür.",
            );
        }

        // Əks istiqamət (a): matrisdə maliyyə səlahiyyəti olan xüsusi rol
        // vidjetləri QAZANIR — rol konstruktoru artıq işləyir.
        $analyst = $this->userWithCustomRole('maliyye_analitiki', [
            Domain::Payments->value => AccessLevel::Full->value,
            Domain::Budget->value => AccessLevel::Full->value,
            Domain::Analytics->value => AccessLevel::Full->value,
            Domain::OwnerDashboard->value => AccessLevel::Full->value,
            Domain::Projects->value => AccessLevel::View->value,
        ], false, 'designer');

        $this->actingAs($analyst);

        $this->assertTrue(AccessMatrix::allows($analyst, Domain::Payments, AccessLevel::Full));

        foreach ([PortfolioFinanceStats::class, CashForecastWidget::class, ProfitabilityWidget::class, OwnerStatsOverview::class] as $widget) {
            $this->assertTrue(
                $widget::canView(),
                "{$widget} matrisi oxumur: Ödənişlər = Tam verilmiş xüsusi rol onu görmür.",
            );
        }

        // Domen ayrıdır: analitikin Mərhələ/Tapşırıq səviyyəsi yoxdur, ona görə
        // mərhələ son tarixləri cədvəli ona AÇILMIR (icazə genişlənmir).
        $this->assertFalse(
            UpcomingDeadlinesWidget::canView(),
            'Maliyyə səlahiyyəti mərhələ qrafikinə giriş VERMƏMƏLİDİR.',
        );

        // Əks istiqamət (b): Mərhələ/Tapşırıq = Tam verilmiş «koordinator»
        // mərhələ cədvəlini qazanır, amma pul vidjetlərini görmür.
        $coordinator = $this->userWithCustomRole('koordinator', [
            Domain::StagesTasks->value => AccessLevel::Full->value,
            Domain::Projects->value => AccessLevel::View->value,
        ], false, 'designer');

        $this->actingAs($coordinator);

        $this->assertTrue(
            UpcomingDeadlinesWidget::canView(),
            'Mərhələ/Tapşırıq = Tam verilmiş xüsusi rol mərhələ cədvəlini görməlidir.',
        );
        $this->assertFalse(PortfolioFinanceStats::canView(), 'PUL SIZMASI: koordinator portfel maliyyəsini görür.');
        $this->assertFalse(CashForecastWidget::canView());
        $this->assertFalse(ProfitabilityWidget::canView());
        $this->assertFalse(OwnerStatsOverview::canView());

        // Standart rollar dəyişmir: layihə menecerinin Analitika = Baxış icazəsi
        // öz layihələri üzrədir, Ödənişlər isə yalnız Baxış — pul vidjeti yoxdur.
        $pm = $this->studio->user('project_manager');
        $this->actingAs($pm);

        $this->assertTrue(AccessMatrix::allows($pm, Domain::Analytics, AccessLevel::View));
        $this->assertFalse(PortfolioFinanceStats::canView(), 'PUL SIZMASI: layihə meneceri portfel maliyyəsini görür.');
        $this->assertFalse(OwnerStatsOverview::canView(), 'Layihə menecerinin Rəhbər paneli domeni Yoxdur-dur.');
        $this->assertTrue(UpcomingDeadlinesWidget::canView(), 'PM-in Mərhələ/Tapşırıq = Tam səlahiyyəti var.');

        // Sahibkar hər beşini saxlayır.
        $this->actingAs($this->studio->user('owner'));

        foreach ([PortfolioFinanceStats::class, CashForecastWidget::class, ProfitabilityWidget::class, OwnerStatsOverview::class, UpcomingDeadlinesWidget::class] as $widget) {
            $this->assertTrue($widget::canView(), "REQRESSİYA: sahibkar {$widget} vidjetini itirdi.");
        }
    }

    // ---------------------------------------------------------------------
    // 6. XÜSUSİ ROLLAR — dərhal təsir edirmi (keş)
    // ---------------------------------------------------------------------

    public function test_role_change_takes_effect_without_a_manual_flush(): void
    {
        $this->seed(RoleSeeder::class);

        $designer = $this->studio->user('designer');
        AccessMatrix::flushCache();

        $this->assertTrue(AccessMatrix::allows($designer, Domain::Brief, AccessLevel::Full), 'Başlanğıc: dizayner Brif = Tam.');

        $narrow = Role::create([
            'tenant_id' => $this->studio->tenant->id,
            'key' => 'dar_rol', 'name' => 'Dar rol',
            'levels' => [Domain::Brief->value => AccessLevel::View->value],
            'own_projects_only' => true, 'is_system' => false, 'active' => true,
        ]);

        $designer->forceFill(['role_id' => $narrow->id])->save();

        // Keş açarına role_id daxildir — `flushCache()` olmadan da dəyişiklik görünür.
        $this->assertFalse(
            AccessMatrix::allows($designer->fresh(), Domain::Brief, AccessLevel::Full),
            'KEŞ PROBLEMİ: istifadəçiyə yeni rol təyin ediləndən sonra köhnə icazə qalır.',
        );
    }

    /**
     * QA TAPINTI [ORTA]: `AccessMatrix::$cache` statikdir və rolun SƏTRİ
     * dəyişdikdə özü boşalmır — app/Support/AccessMatrix.php:127. Yalnız
     * `CreateRole`/`EditRole` səhifələri əl ilə `flushCache()` çağırır. Rol
     * sətri Filament panelindən KƏNAR (konsol əmri, seeder, API, queue işçisi)
     * dəyişdirilərsə, həmin prosesdə köhnə icazə qüvvədə qalır.
     */
    public function test_editing_a_role_row_outside_filament_leaves_a_stale_cache(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::create([
            'tenant_id' => $this->studio->tenant->id,
            'key' => 'kesh_testi', 'name' => 'Keş testi',
            'levels' => [Domain::Payments->value => AccessLevel::Full->value],
            'own_projects_only' => false, 'is_system' => false, 'active' => true,
        ]);

        $user = $this->studio->user('visualizer');
        $user->forceFill(['role_id' => $role->id])->save();
        AccessMatrix::flushCache();

        $this->assertTrue(AccessMatrix::allows($user->fresh(), Domain::Payments, AccessLevel::Full));

        // Rol sətri paneldən kənar deaktiv edilir / səviyyələri sıfırlanır:
        $role->forceFill(['levels' => [], 'active' => false])->save();

        $this->assertTrue(
            AccessMatrix::allows($user->fresh(), Domain::Payments, AccessLevel::Full),
            'Bu test qırmızıdırsa keş artıq rol dəyişikliyində özü boşalır — tapıntı bağlanıb.',
        );

        AccessMatrix::flushCache();
        $this->assertFalse(
            AccessMatrix::allows($user->fresh(), Domain::Payments, AccessLevel::Full),
            'flushCache()-dən sonra deaktiv rol bütün icazələri itirməlidir.',
        );
    }

    public function test_deactivated_role_revokes_every_grant(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->userWithCustomRole('deaktiv_rol', [
            Domain::Projects->value => AccessLevel::Full->value,
            Domain::Payments->value => AccessLevel::Full->value,
        ], false);

        $this->assertTrue(AccessMatrix::allows($user, Domain::Payments, AccessLevel::Full));

        Role::where('key', 'deaktiv_rol')->update(['active' => false]);
        AccessMatrix::flushCache();

        $this->assertFalse(
            AccessMatrix::allows($user->fresh(), Domain::Payments, AccessLevel::View),
            'Deaktiv rol hələ də icazə verir.',
        );
        $this->assertFalse($user->fresh()->can('viewAny', Payment::class));
    }

    public function test_a_role_id_pointing_at_another_studio_grants_nothing(): void
    {
        $this->seed(RoleSeeder::class);

        $other = StudioWorld::make('yadstudiya');

        $foreignRole = Role::create([
            'tenant_id' => $other->tenant->id,
            'key' => 'yad_super', 'name' => 'Yad super rol',
            'levels' => array_fill_keys(array_map(fn (Domain $d) => $d->value, Domain::cases()), AccessLevel::Full->value),
            'own_projects_only' => false, 'is_system' => false, 'active' => true,
        ]);

        $victim = $this->studio->user('visualizer');
        $victim->forceFill(['role_id' => $foreignRole->id])->save();
        AccessMatrix::flushCache();

        $this->assertFalse(
            AccessMatrix::allows($victim->fresh(), Domain::Payments, AccessLevel::View),
            'STUDİYALARARASI SIZMA: başqa studiyanın rolu icazə verdi.',
        );
    }

    // ---------------------------------------------------------------------
    // 7. DEAKTİV İSTİFADƏÇİ
    // ---------------------------------------------------------------------

    public function test_an_open_session_dies_when_the_employee_is_deactivated(): void
    {
        $designer = $this->studio->user('designer');

        $this->assertSame(200, $this->actingAs($designer)->get(route('filament.app.pages.dashboard'))->status());

        $designer->forceFill(['is_active' => false])->save();

        $status = $this->actingAs($designer->fresh())->get(route('filament.app.pages.dashboard'))->status();

        $this->assertNotSame(200, $status, 'Deaktiv edilmiş işçinin açıq sessiyası panelə girməyə davam edir.');
        $this->assertContains($status, [302, 403], "Deaktiv işçi gözlənilməz status aldı: {$status}.");
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ. Əvvəl `is_active=false` YALNIZ panelə
     * giriş qapısında (`User::canAccessPanel()`) yoxlanılırdı, policy qatı isə
     * onu ümumiyyətlə oxumurdu — yəni deaktiv edilmiş işçi panel-XARİCİ
     * marşrutlarda (fayl endirmə, təqvim feed-i, çat) hələ də avtorizasiyadan
     * keçirdi.
     *
     * İndi `AccessMatrix::resolve()` AÇIQ `is_active === false` dəyərində boş
     * matris qaytarır: deaktiv işçi HEÇ BİR domendə heç nə görmür, yəni policy
     * qatı da bağlıdır (fail-closed).
     */
    public function test_deactivating_an_employee_revokes_every_domain(): void
    {
        $designer = $this->studio->user('designer');

        // Başlanğıc vəziyyət: aktiv işçinin icazəsi var (testin özü mənalı olsun).
        $this->assertTrue($designer->can('view', $this->studio->project));
        $this->assertTrue(AccessMatrix::allows($designer, Domain::Brief, AccessLevel::View));

        $designer->forceFill(['is_active' => false])->save();
        AccessMatrix::flushCache();

        $deactivated = $designer->fresh();

        // DÜZƏLDİLDİ: policy qatı da `is_active`-i oxuyur.
        $this->assertFalse(
            $deactivated->can('view', $this->studio->project),
            'Deaktiv işçi hələ də layihəni görür — policy qatı `is_active` oxumur.',
        );

        foreach (Domain::cases() as $domain) {
            $this->assertSame(
                AccessLevel::None,
                AccessMatrix::level($deactivated, $domain),
                "Deaktiv işçi «{$domain->value}» domenində hələ də səviyyə alır.",
            );
        }

        foreach ([Project::class, Task::class, Payment::class, Client::class] as $model) {
            $this->assertFalse($deactivated->can('viewAny', $model), "Deaktiv işçi {$model} siyahısını görür.");
        }

        // Panel-xarici route: fayl yükləmə `auth:web` + policy ilə işləyir.
        // Əvvəl 404 gəlirdi (gate KEÇİRDİ, fayl sadəcə diskdə yox idi) — indi
        // avtorizasiyanın özü rədd edir.
        $status = $this->actingAs($deactivated)
            ->get(route('files.download', ['file' => $this->studio->internalFile->id]))->status();

        $this->assertSame(
            403,
            $status,
            "Deaktiv işçi fayl yükləmə gate-indən keçir (status {$status}) — 403 gözlənilir.",
        );
    }

    // ---------------------------------------------------------------------
    // 8. ROLSUZ / YAD ROLLU İSTİFADƏÇİ
    // ---------------------------------------------------------------------

    public function test_a_user_without_a_role_cannot_even_be_created(): void
    {
        // Yaxşı xəbər: `users.role` sütunu NOT NULL-dur — rolsuz işçi ümumiyyətlə
        // bazaya düşmür, yəni «defolt hər şeyə icazə» riski YOXDUR.
        $this->expectException(QueryException::class);

        app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => User::create([
            'name' => 'Rolsuz', 'email' => 'rolsuz@rolqa.test', 'password' => 'secret123',
            'role' => null, 'is_active' => true,
        ]));
    }

    public function test_a_user_whose_role_grants_nothing_sees_nothing(): void
    {
        $orphan = $this->userWithCustomRole('bos_rol', [], true);

        AccessMatrix::flushCache();

        foreach (Domain::cases() as $domain) {
            $this->assertSame(
                AccessLevel::None,
                AccessMatrix::level($orphan, $domain),
                "BLOKER olardı: rolsuz istifadəçi «{$domain->value}» domenində səviyyə aldı.",
            );
        }

        foreach ([Project::class, Task::class, Payment::class, Client::class, User::class] as $model) {
            $this->assertFalse($orphan->can('viewAny', $model), "Rolsuz istifadəçi {$model} siyahısını görür.");
        }

        $this->assertFalse(RoleResource::canAccess());
        $this->assertFalse(TenantResource::canAccess());
        $this->assertFalse(TranslationResource::canAccess());

        $this->actingAs($orphan);
        $this->assertFalse(Profitability::canAccess());
        $this->assertFalse(Attention::canAccess());
        $this->assertFalse(TaskPlanner::canAccess());
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ. Əvvəl `users.role` sütunundakı enum-a
     * uyğun OLMAYAN dəyər (miqrasiya qalığı, əl ilə SQL, enum-dan çıxarılmış
     * rol) standart enum cast-ında `ValueError` atırdı — istifadəçi icazə rəddi
     * yox, 500 səhifəsi görürdü (fail loud).
     *
     * İndi sütun `App\Casts\SafeStaffRole` ilə cast olunur: yad sətir `null`
     * olur, `AccessMatrix` isə rolsuz istifadəçiyə heç bir domendə icazə vermir
     * — sistem DAHA MƏHDUD davranır, daha geniş yox (fail-closed).
     */
    public function test_an_unknown_role_string_denies_instead_of_crashing(): void
    {
        $user = $this->studio->user('designer');
        DB::table('users')->where('id', $user->id)->update(['role' => 'super_hacker']);
        AccessMatrix::flushCache();

        $reloaded = User::withoutGlobalScopes()->find($user->id);

        // Cast partlamır, tanınmayan dəyər `null` olur.
        $this->assertNull($reloaded->role, 'Yad rol sətri `null`-a çevrilməlidir (SafeStaffRole).');

        // İcazə SIZMIR: heç bir domendə səviyyə yoxdur.
        foreach (Domain::cases() as $domain) {
            $this->assertSame(
                AccessLevel::None,
                AccessMatrix::level($reloaded, $domain),
                "BLOKER olardı: yad rollu istifadəçi «{$domain->value}» domenində səviyyə aldı.",
            );
        }

        foreach ([Project::class, Task::class, Payment::class, Client::class, User::class] as $model) {
            $this->assertFalse($reloaded->can('viewAny', $model), "Yad rollu istifadəçi {$model} siyahısını görür.");
        }

        $this->assertFalse($reloaded->can('view', $this->studio->project));

        // 500 də gəlmir — panel açılır, sadəcə boş.
        $status = $this->actingAs($reloaded)->get(route('filament.app.pages.dashboard'))->status();

        $this->assertNotSame(500, $status, "Yad rol dəyəri hələ də 500 verir (status {$status}).");
    }

    // ---------------------------------------------------------------------
    // 9. POLICY-SİZ MODELLƏR
    // ---------------------------------------------------------------------

    /**
     * QA TAPINTI [ORTA]: aşağıdakı modellərin policy-si YOXDUR. Onların
     * əksəriyyəti yalnız servis/portal qatından əlçatandır, lakin `Role`,
     * `ChatMessage` və `Comment` panel səthinə çıxır və yalnız səhifə
     * gate-i / servis yoxlaması ilə qorunur (policy qatı boşdur).
     */
    public function test_models_without_a_policy_are_enumerated(): void
    {
        $models = collect(glob(app_path('Models/*.php')))
            ->map(fn (string $path) => basename($path, '.php'))
            ->reject(fn (string $name) => in_array($name, ['Concerns'], true))
            ->values();

        $withoutPolicy = $models
            ->reject(fn (string $name) => file_exists(app_path("Policies/{$name}Policy.php")))
            ->values()
            ->all();

        sort($withoutPolicy);

        $this->assertSame([
            'Brief',
            'BriefAnswer',
            'BriefComment',
            'BriefRoom',
            'BriefSection',
            'BriefSectionState',
            'BriefTemplate',
            'BriefVersion',
            'ChatMessage',
            'ClientContactLog',
            'ClientUser',
            'Comment',
            'Role',
            'Setting',
            'StageTemplate',
            'StageTemplateItem',
        ], $withoutPolicy, 'Policy-siz modellərin siyahısı dəyişib — yeni model policy-siz qalıb ola bilər.');

        // `Role` policy-siz olduğu üçün yeganə müdafiə xətti resursun gate-idir.
        $this->actingAs($this->studio->user('designer'));
        $this->assertFalse(RoleResource::canAccess(), 'Rollar resursu yalnız canAccess() ilə qorunur — o da açıqdırsa BLOKER olardı.');
    }

    // ---------------------------------------------------------------------
    // Köməkçilər
    // ---------------------------------------------------------------------

    /** @return array{0: Stage, 1: Task, 2: ProjectFile, 3: BudgetLine, 4: Payment} */
    private function buildOtherProjectRecords(): array
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, function () {
            $other = $this->studio->otherProject;

            $stage = $other->stages()->create(['name' => 'Yad mərhələ', 'position' => 1, 'weight' => 1, 'status' => 'in_progress']);

            $task = Task::create([
                'project_id' => $other->id,
                'stage_id' => $stage->id,
                'title' => 'YAD-LAYIHE-TAPSIRIGI',
                'status' => 'todo',
                'assignee_user_id' => $this->studio->user('owner')->id,
            ]);

            $file = ProjectFile::create([
                'project_id' => $other->id, 'category' => 'plan', 'visibility' => 'internal',
                'title' => 'Yad cizgi', 'file_path' => 'files/yad.dwg',
            ]);

            $budgetLine = $other->budgetLines()->create([
                'work_type' => 'Yad iş', 'unit' => 'm2', 'qty' => 1,
                'work_price' => 99999, 'material_price' => 0, 'position' => 9,
            ]);

            $payment = $other->payments()->create([
                'title' => 'Yad ödəniş', 'amount' => 77777, 'status' => 'pending', 'due_date' => now()->addWeek(),
            ]);

            return [$stage, $task, $file, $budgetLine, $payment];
        });
    }

    /** @param array<string, int> $levels */
    private function userWithCustomRole(string $key, array $levels, bool $ownOnly, string $baseRole = 'visualizer'): User
    {
        $role = Role::firstOrCreate(
            ['tenant_id' => $this->studio->tenant->id, 'key' => $key],
            ['name' => $key, 'levels' => $levels, 'own_projects_only' => $ownOnly, 'is_system' => false, 'active' => true],
        );

        $user = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => User::create([
            'name' => $key, 'email' => $key.'-'.uniqid().'@rolqa.test', 'password' => 'secret123',
            'role' => $baseRole, 'role_id' => $role->id, 'is_active' => true,
        ]));

        AccessMatrix::flushCache();

        return $user->fresh();
    }
}
