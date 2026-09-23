<?php

namespace Tests\Feature\Fix;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Enums\StageStatus;
use App\Filament\Pages\Calendar;
use App\Filament\Pages\ChatCenter;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\CashForecastWidget;
use App\Filament\Widgets\OwnerStatsOverview;
use App\Filament\Widgets\PortfolioFinanceStats;
use App\Filament\Widgets\ProfitabilityWidget;
use App\Filament\Widgets\UpcomingDeadlinesWidget;
use App\Models\Role;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA tapıntılarının DÜZƏLİŞ testləri (RolePermissionQaTest və AdminModulesBQaTest
 * həmin tapıntıları faktiki davranış kimi təsbit edirdi — bu fayl isə GÖZLƏNİLƏN
 * davranışı yoxlayır).
 *
 * Hər tapıntı üçün iki istiqamət də sınanır: «görməli olan rol görür» VƏ
 * «görməməli olan rol görmür» — yalnız birincisi olsa, düzəliş hamını bağlamaqla
 * da «yaşıl» görünə bilərdi.
 */
class DashboardScopeFixTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    /** Yad (üzvü olmadığımız) layihənin tapşırıq başlığı — səhifədə OLMAMALIDIR. */
    private const FOREIGN_TASK = 'YAD-LAYIHE-TAPSIRIGI-QA';

    /** Öz layihəmizin tapşırığı — səhifədə OLMALIDIR. */
    private const OWN_TASK = 'OZ-LAYIHE-TAPSIRIGI-QA';

    protected function setUp(): void
    {
        parent::setUp();

        AccessMatrix::flushCache();
        $this->studio = StudioWorld::make('alfa');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            // Öz layihədə (dizayner üzvdür) gecikmiş tapşırıq.
            Task::create([
                'project_id' => $this->studio->project->id,
                'stage_id' => $this->studio->stage->id,
                'title' => self::OWN_TASK,
                'status' => 'todo',
                'deadline' => today()->subDay(),
                'assignee_user_id' => $this->studio->user('designer')->id,
            ]);

            // Yad layihədə (ortaq üzv yoxdur) gecikmiş tapşırıq.
            $foreignStage = $this->studio->otherProject->stages()->create([
                'name' => 'Yad mərhələ', 'position' => 1, 'weight' => 1, 'status' => 'in_progress',
            ]);

            Task::create([
                'project_id' => $this->studio->otherProject->id,
                'stage_id' => $foreignStage->id,
                'title' => self::FOREIGN_TASK,
                'status' => 'todo',
                'deadline' => today()->subDay(),
                'assignee_user_id' => $this->studio->user('owner')->id,
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1 + 2. İdarəetmə paneli — yad layihə və tapşırıq sızması, gecikmə sayı
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * TAPINTI 1 [CİDDİ]: müştərinin birbaşa şikayəti — dizayner/vizualizator üzvü
     * OLMADIĞI layihənin adını və tapşırıq BAŞLIĞINI panelin özündə görürdü.
     */
    #[DataProvider('ownProjectsOnlyRoles')]
    public function test_dashboard_hides_foreign_projects_and_tasks(string $role): void
    {
        $html = $this->pageFor($role);

        $this->assertStringNotContainsString(
            $this->studio->otherProject->name,
            $html,
            "SIZMA: «{$role}» üzvü olmadığı layihənin ADINI paneldə görür.",
        );

        $this->assertStringNotContainsString(
            self::FOREIGN_TASK,
            $html,
            "SIZMA: «{$role}» üzvü olmadığı layihənin tapşırıq BAŞLIĞINI paneldə görür.",
        );
    }

    /**
     * Əks istiqamət: filtr həddən artıq kəsməməlidir — dizayner ÖZ layihəsini və
     * öz tapşırığını paneldə görməyə davam edir.
     */
    public function test_dashboard_still_shows_own_project_and_task_to_a_member(): void
    {
        $html = $this->pageFor('designer');

        $this->assertStringContainsString($this->studio->project->name, $html);
        $this->assertStringContainsString(self::OWN_TASK, $html);
    }

    /** Sahibkar və mühasib bütün layihələri görməkdə davam edir (mövcud davranış). */
    #[DataProvider('allProjectsRoles')]
    public function test_dashboard_still_shows_every_project_to_portfolio_roles(string $role): void
    {
        $html = $this->pageFor($role);

        $this->assertStringContainsString($this->studio->project->name, $html);
        $this->assertStringContainsString($this->studio->otherProject->name, $html);
        $this->assertStringContainsString(self::FOREIGN_TASK, $html);
    }

    /**
     * TAPINTI 2 [ORTA]: «Gecikmiş tapşırıqlar» rəqəmi hamıya studiyanın ÜMUMİ
     * sayını göstərirdi. İndi öz layihələri üzrədir: dizayner 1, sahibkar 2.
     */
    public function test_overdue_tile_counts_only_own_projects(): void
    {
        $this->assertSame('1', $this->overdueTileValue('designer'), 'Dizayner yad layihənin gecikməsini də sayır.');
        $this->assertSame('1', $this->overdueTileValue('project_manager'));
        $this->assertSame('2', $this->overdueTileValue('owner'), 'Sahibkar bütün gecikmələri görməlidir.');
        $this->assertSame('2', $this->overdueTileValue('accountant'), 'Mühasib bütün gecikmələri görməlidir.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Vidjetlər — matris, sabit rol adı yox
    // ─────────────────────────────────────────────────────────────────────────

    /** Standart altı rolun MÖVCUD davranışı dəyişməməlidir. */
    #[DataProvider('widgetVisibilityMatrix')]
    public function test_standard_roles_keep_their_widget_visibility(string $widget, string $role, bool $expected): void
    {
        $this->actingAsFresh($role);

        $this->assertSame(
            $expected,
            $widget::canView(),
            "«{$role}» üçün {$widget} görünüşü dəyişib — standart rolların davranışı pozulmamalıdır.",
        );
    }

    /**
     * TAPINTI 3 (a): bütün domenləri bağlanmış, amma `role` sütunu hələ
     * `accountant` qalan istifadəçi maliyyə vidjetlərini GÖRMƏMƏLİDİR.
     */
    public function test_blocked_custom_role_loses_finance_widgets_despite_accountant_role_column(): void
    {
        $blocked = $this->userWithCustomRole('arxiv_ishcisi', [
            Domain::Projects->value => AccessLevel::View->value,
        ], 'accountant');

        $this->actingAs($blocked);

        $this->assertFalse(AccessMatrix::allows($blocked, Domain::Payments, AccessLevel::View));

        foreach ($this->financeWidgets() as $widget) {
            $this->assertFalse(
                $widget::canView(),
                "PUL SIZMASI: domenləri bağlanmış istifadəçi {$widget} vidjetini görür (sabit rol adı oxunur).",
            );
        }
    }

    /**
     * TAPINTI 3 (b): matrisdə maliyyə domenləri TAM verilmiş xüsusi rol
     * vidjetləri GÖRMƏLİDİR — rol konstruktoru bu ekranlarda ölü olmamalıdır.
     */
    public function test_custom_finance_role_gains_the_finance_widgets(): void
    {
        $analyst = $this->userWithCustomRole('maliyye_analitiki', [
            Domain::Projects->value => AccessLevel::View->value,
            Domain::Budget->value => AccessLevel::Full->value,
            Domain::Payments->value => AccessLevel::Full->value,
            Domain::OwnerDashboard->value => AccessLevel::Full->value,
            Domain::Analytics->value => AccessLevel::Full->value,
        ], 'designer');

        $this->actingAs($analyst);

        foreach ($this->financeWidgets() as $widget) {
            $this->assertTrue(
                $widget::canView(),
                "ÖLÜ KONSTRUKTOR: matrisdə Ödənişlər = Tam olan xüsusi rol {$widget} vidjetini görmür.",
            );
        }

        // Mərhələ/Tapşırıq domeni bu rolda boşdur — qrafik vidjeti açılmamalıdır.
        $this->assertFalse(UpcomingDeadlinesWidget::canView());
    }

    /** Eyni qayda mərhələ vidjeti üçün: Mərhələ/Tapşırıq = Tam verilən xüsusi rol onu görür. */
    public function test_custom_coordinator_role_gains_the_deadlines_widget(): void
    {
        $coordinator = $this->userWithCustomRole('koordinator', [
            Domain::Projects->value => AccessLevel::View->value,
            Domain::StagesTasks->value => AccessLevel::Full->value,
        ], 'visualizer');

        $this->actingAs($coordinator);

        $this->assertTrue(
            UpcomingDeadlinesWidget::canView(),
            'ÖLÜ KONSTRUKTOR: Mərhələ/Tapşırıq = Tam olan xüsusi rol qrafik vidjetini görmür.',
        );

        // Əks istiqamət: `role` sütunu `project_manager` qalsa da, domen bağlıdırsa vidjet yoxdur.
        $blockedPm = $this->userWithCustomRole('kohne_menecer', [
            Domain::Projects->value => AccessLevel::View->value,
        ], 'project_manager');

        $this->actingAs($blockedPm);

        $this->assertFalse(
            UpcomingDeadlinesWidget::canView(),
            'Domenləri bağlanmış istifadəçi sabit rol adı sayəsində vidjeti görməyə davam edir.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. UpcomingDeadlinesWidget — layihə üzrə kəsilmə
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * TAPINTI 4 [ORTA]: layihə meneceri üzvü OLMADIĞI layihənin mərhələsini
     * cədvəldə görürdü.
     */
    public function test_deadlines_widget_is_scoped_to_own_projects(): void
    {
        $foreign = $this->seedUpcomingStages();

        $pm = $this->actingAsFresh('project_manager');
        $this->assertFalse($this->studio->otherProject->hasMember($pm));

        Livewire::test(UpcomingDeadlinesWidget::class)
            ->assertCanNotSeeTableRecords(Stage::whereKey($foreign->id)->get())
            ->assertCanSeeTableRecords(Stage::where('project_id', $this->studio->project->id)
                ->whereNotNull('date_plan_end')->get());
    }

    /** Sahibkar üçün məhdudiyyət yoxdur — hər iki layihənin mərhələsi görünür. */
    public function test_deadlines_widget_still_shows_everything_to_the_owner(): void
    {
        $foreign = $this->seedUpcomingStages();

        $this->actingAsFresh('owner');

        Livewire::test(UpcomingDeadlinesWidget::class)
            ->assertCanSeeTableRecords(Stage::whereKey($foreign->id)->get());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Calendar + ChatCenter — səhifə qatında gate
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * TAPINTI 5 [ORTA]: hər iki səhifə gate-siz idi — bütün domenləri boş olan
     * rol onları 200 ilə açırdı (TZ §5.20: icazə serverdə tətbiq olunur).
     */
    public function test_calendar_and_chat_center_are_closed_to_a_role_without_domains(): void
    {
        $nobody = $this->userWithCustomRole('hec_ne', [], 'visualizer');

        $this->actingAs($nobody);
        $this->assertFalse(Calendar::canAccess(), 'Təqvim səhifəsi hələ gate-sizdir.');
        $this->assertFalse(ChatCenter::canAccess(), 'Çat səhifəsi hələ gate-sizdir.');

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->assertSame(403, $this->actingAs($nobody)->get(route('filament.app.pages.calendar'))->status());

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->assertSame(403, $this->actingAs($nobody->fresh())->get(route('filament.app.pages.chat-center'))->status());
    }

    /** Standart altı rolun heç biri girişini itirmir. */
    #[DataProvider('standardRoles')]
    public function test_calendar_and_chat_center_stay_open_to_every_standard_role(string $role): void
    {
        $this->assertSame(200, $this->visit($role, 'filament.app.pages.calendar')->status(), "«{$role}» Təqvimi aça bilmir.");
        $this->assertSame(200, $this->visit($role, 'filament.app.pages.chat-center')->status(), "«{$role}» Çatı aça bilmir.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data provider-lər
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function standardRoles(): array
    {
        $out = [];
        foreach (['owner', 'project_manager', 'designer', 'visualizer', 'procurement', 'accountant'] as $role) {
            $out[$role] = [$role];
        }

        return $out;
    }

    /** Matrisdə «yalnız öz layihələri» olan rollar. @return array<string, array{0: string}> */
    public static function ownProjectsOnlyRoles(): array
    {
        $out = [];
        foreach (['project_manager', 'designer', 'visualizer', 'procurement'] as $role) {
            $out[$role] = [$role];
        }

        return $out;
    }

    /** Portfel səviyyəli rollar. @return array<string, array{0: string}> */
    public static function allProjectsRoles(): array
    {
        return ['owner' => ['owner'], 'accountant' => ['accountant']];
    }

    /**
     * Standart rolların vidjet görünüşü — düzəlişdən ƏVVƏLKİ ilə eyni olmalıdır.
     *
     * @return array<string, array{0: class-string, 1: string, 2: bool}>
     */
    public static function widgetVisibilityMatrix(): array
    {
        $finance = [PortfolioFinanceStats::class, CashForecastWidget::class, ProfitabilityWidget::class, OwnerStatsOverview::class];

        $cases = [];

        foreach ($finance as $widget) {
            foreach (self::standardRoles() as $role => $_) {
                $short = class_basename($widget);
                // Maliyyə vidjetləri: yalnız sahibkar və mühasib.
                $cases["{$role} → {$short}"] = [$widget, $role, in_array($role, ['owner', 'accountant'], true)];
            }
        }

        foreach (self::standardRoles() as $role => $_) {
            // Qrafik vidjeti: yalnız sahibkar və layihə meneceri.
            $cases["{$role} → UpcomingDeadlinesWidget"] = [
                UpcomingDeadlinesWidget::class, $role, in_array($role, ['owner', 'project_manager'], true),
            ];
        }

        return $cases;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Köməkçilər
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<int, class-string> */
    private function financeWidgets(): array
    {
        return [PortfolioFinanceStats::class, CashForecastWidget::class, ProfitabilityWidget::class, OwnerStatsOverview::class];
    }

    /**
     * Hər sorğu təmiz sessiya ilə başlayır: bir testin içində istifadəçi
     * dəyişdikdə AuthenticateSession köhnə sessiyanı etibarsız sayır.
     */
    private function visit(string $role, string $route): TestResponse
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->studio->user($role))->get(route($route));
    }

    private function pageFor(string $role): string
    {
        $response = $this->visit($role, 'filament.app.pages.dashboard');
        $response->assertOk();

        return $response->getContent();
    }

    /** Autentifikasiyanı təmiz sessiya ilə qurur və istifadəçini qaytarır. */
    private function actingAsFresh(string $role): User
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        AccessMatrix::flushCache();

        $user = $this->studio->user($role);
        $this->actingAs($user);

        return $user;
    }

    /** «Gecikmiş tapşırıqlar» tile-ının dəyəri — həmişə sonuncu tile-dır. */
    private function overdueTileValue(string $role): string
    {
        $this->actingAsFresh($role);

        $tiles = (new Dashboard)->stats();
        $overdue = collect($tiles)->firstWhere('label', 'Gecikmiş tapşırıqlar');

        $this->assertNotNull($overdue, 'Gecikmə tile-ı paneldən itib.');

        return $overdue['value'];
    }

    /** Hər iki layihədə yaxın 14 günə mərhələ; yad layihəninkini qaytarır. */
    private function seedUpcomingStages(): Stage
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): Stage {
            $this->studio->stage->forceFill([
                'status' => StageStatus::InProgress->value,
                'date_plan_end' => today()->addDays(3),
            ])->save();

            return $this->studio->otherProject->stages()->create([
                'name' => 'Yad layihənin mərhələsi',
                'position' => 1, 'weight' => 1,
                'status' => StageStatus::InProgress->value,
                'date_plan_end' => today()->addDays(3),
            ]);
        });
    }

    /** @param array<string, int> $levels */
    private function userWithCustomRole(string $key, array $levels, string $baseRole): User
    {
        $role = Role::firstOrCreate(
            ['tenant_id' => $this->studio->tenant->id, 'key' => $key],
            ['name' => $key, 'levels' => $levels, 'own_projects_only' => true, 'is_system' => false, 'active' => true],
        );

        $user = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => User::create([
            'name' => $key, 'email' => $key.'-'.uniqid().'@fixqa.test', 'password' => 'secret123',
            'role' => $baseRole, 'role_id' => $role->id, 'is_active' => true,
        ]));

        AccessMatrix::flushCache();

        return $user->fresh();
    }
}
