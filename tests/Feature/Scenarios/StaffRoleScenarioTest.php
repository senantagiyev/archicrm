<?php

namespace Tests\Feature\Scenarios;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Role;
use App\Support\AccessMatrix;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Scenario: one studio, six people, one access matrix (TZ §5.4). Every role
 * walks the panel over real HTTP and must land exactly where the matrix says —
 * no further. The matrix is only worth something if the SERVER enforces it, so
 * these hit URLs directly rather than checking whether a menu item is hidden.
 *
 * One request per test case on purpose: Filament's AuthenticateSession logs the
 * session out when the acting user changes mid-request-cycle, which would turn
 * every second assertion in a loop into a meaningless 302.
 */
class StaffRoleScenarioTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('matris');
    }

    /**
     * Every (page, role) pair the matrix has an opinion about.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function panelAccess(): array
    {
        $pages = [
            // page key => [route, allowed roles]
            'clients' => ['filament.app.resources.clients.index', ['owner', 'project_manager', 'designer', 'accountant']],
            'leads' => ['filament.app.resources.leads.index', ['owner', 'project_manager', 'designer', 'accountant']],
            'expenses' => ['filament.app.resources.expenses.index', ['owner', 'project_manager', 'accountant']],
            'invoices' => ['filament.app.resources.invoices.index', ['owner', 'project_manager', 'accountant']],
            'suppliers' => ['filament.app.resources.suppliers.index', ['owner', 'project_manager', 'designer', 'procurement', 'accountant']],
            'purchase-orders' => ['filament.app.resources.purchase-orders.index', ['owner', 'project_manager', 'designer', 'procurement', 'accountant']],
            // Role constructor changes the permission matrix itself — owner only.
            'roles' => ['filament.app.resources.roles.index', ['owner']],
            // Studio registry is the platform admin's, not any studio role's.
            'tenants' => ['filament.app.resources.tenants.index', []],
            // Studio-wide margins: owner and accountant. A PM's Analytics=View is
            // scoped to their own projects, so the portfolio page stays closed.
            'profitability' => ['filament.app.pages.profitability', ['owner', 'accountant']],
        ];

        $roles = ['owner', 'project_manager', 'designer', 'visualizer', 'procurement', 'accountant'];
        $cases = [];

        foreach ($pages as $page => [$route, $allowedRoles]) {
            foreach ($roles as $role) {
                $cases["{$role} → {$page}"] = [$route, $role, in_array($role, $allowedRoles, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('panelAccess')]
    public function test_panel_page_follows_the_access_matrix(string $route, string $role, bool $allowed): void
    {
        $status = $this->actingAs($this->studio->user($role))->get(route($route))->status();

        if ($allowed) {
            $this->assertSame(200, $status, "«{$role}» rolu icazəsi olduğu halda {$route} səhifəsinə girə bilmədi (status {$status}).");

            return;
        }

        $this->assertNotSame(500, $status, "«{$role}» rolu {$route} səhifəsində 500 xətası aldı — rədd 403 olmalıdır, çökmə yox.");
        $this->assertContains($status, [403, 404], "«{$role}» rolu matrisə zidd olaraq {$route} səhifəsinə girdi (status {$status}).");
    }

    public function test_a_designer_who_is_not_a_project_member_cannot_open_that_project(): void
    {
        $designer = $this->studio->user('designer');

        // Member of `project`, deliberately not of `otherProject`.
        $this->assertTrue($designer->can('view', $this->studio->project));
        $this->assertFalse(
            $designer->can('view', $this->studio->otherProject),
            'Dizayner üzv olmadığı layihəni görə bilir.',
        );

        $status = $this->actingAs($designer)
            ->get(route('filament.app.resources.projects.edit', ['record' => $this->studio->otherProject->id]))
            ->status();

        $this->assertContains($status, [403, 404], 'Dizayner üzv olmadığı layihəni URL ilə açdı.');
    }

    public function test_a_visualizer_cannot_touch_money_anywhere(): void
    {
        $viz = $this->studio->user('visualizer');

        $this->assertFalse($viz->can('view', $this->studio->payment));
        $this->assertFalse($viz->can('update', $this->studio->payment));
        $this->assertFalse($viz->can('view', $this->studio->budgetLine));
        $this->assertFalse($viz->can('update', $this->studio->budgetLine));
        $this->assertFalse($viz->can('view', $this->studio->procurementItem));
    }

    public function test_a_designer_cannot_see_payments_but_may_read_the_budget(): void
    {
        $designer = $this->studio->user('designer');

        $this->assertFalse($designer->can('view', $this->studio->payment), 'Dizaynerə ödənişlər qapalı olmalıdır.');
        $this->assertTrue($designer->can('view', $this->studio->budgetLine), 'Dizayner smetanı görməlidir (View).');
        $this->assertFalse($designer->can('update', $this->studio->budgetLine), 'Dizayner smetanı redaktə etməməlidir.');
    }

    public function test_the_accountant_owns_money_but_cannot_edit_procurement(): void
    {
        $accountant = $this->studio->user('accountant');

        $this->assertTrue($accountant->can('update', $this->studio->payment));
        $this->assertTrue($accountant->can('update', $this->studio->budgetLine));
        $this->assertFalse(
            $accountant->can('update', $this->studio->procurementItem),
            'Mühasib komplektasiyanı redaktə edə bilir — matrisdə yalnız View var.',
        );
    }

    public function test_procurement_role_owns_procurement_but_not_payments(): void
    {
        $procurement = $this->studio->user('procurement');

        $this->assertTrue($procurement->can('update', $this->studio->procurementItem));
        $this->assertFalse($procurement->can('view', $this->studio->payment));
        $this->assertFalse($procurement->can('update', $this->studio->budgetLine));
    }

    /**
     * The role constructor is only real if the pages read the matrix. A page that
     * hardcodes `role === Accountant` silently ignores every custom role an owner
     * builds — the feature looks present and does nothing.
     */
    public function test_a_custom_role_with_full_analytics_reaches_the_profitability_page(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::create([
            'key' => 'finance_analyst',
            'name' => 'Maliyyə analitiki',
            'levels' => [
                Domain::Analytics->value => AccessLevel::Full->value,
                Domain::OwnerDashboard->value => AccessLevel::Full->value,
                Domain::Payments->value => AccessLevel::Full->value,
                Domain::Budget->value => AccessLevel::Full->value,
                Domain::Projects->value => AccessLevel::View->value,
            ],
            'own_projects_only' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $analyst = $this->studio->user('designer');
        $analyst->forceFill(['role_id' => $role->id])->save();
        AccessMatrix::flushCache();

        $this->assertTrue(
            AccessMatrix::allows($analyst->fresh(), Domain::Analytics, AccessLevel::Full),
            'Xüsusi rol matrisdə Analitika=Tam verir, amma AccessMatrix bunu görmür.',
        );

        $status = $this->actingAs($analyst->fresh())->get(route('filament.app.pages.profitability'))->status();

        $this->assertSame(
            200,
            $status,
            'Analitika=Tam verilmiş xüsusi rol rentabellik səhifəsinə girə bilmir — səhifə matrisi deyil, sabit rol adını yoxlayır.',
        );
    }

    public function test_a_deactivated_employee_is_locked_out_of_the_panel(): void
    {
        $designer = $this->studio->user('designer');
        $designer->forceFill(['is_active' => false])->save();

        $status = $this->actingAs($designer->fresh())->get(route('filament.app.pages.dashboard'))->status();

        $this->assertNotSame(200, $status, 'Deaktiv edilmiş işçi panelə girə bildi.');
    }
}
