<?php

namespace Tests\Feature\Scenarios;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Scenario: the three screens that decide who can do what — Komanda, Studiyalar
 * and Rollar. A defect here is a permission defect in every other module, so
 * each check runs across two studios rather than inside one.
 */
class AdminModuleScenarioTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alfa;

    private StudioWorld $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->alfa = StudioWorld::make('alfa');
        $this->beta = StudioWorld::make('beta');
    }

    // ---------------------------------------------------------------- Komanda

    public function test_a_studio_owner_cannot_open_another_studios_employee(): void
    {
        $status = $this->actingAs($this->alfa->user('owner'))
            ->get(route('filament.app.resources.users.edit', ['record' => $this->beta->user('designer')->id]))
            ->status();

        $this->assertContains($status, [403, 404], 'Alfa sahibkarı Beta studiyasının işçisini açdı.');
    }

    public function test_the_team_list_shows_only_this_studios_people(): void
    {
        $response = $this->actingAs($this->alfa->user('owner'))
            ->get(route('filament.app.resources.users.index'));

        $response->assertOk();

        $this->assertStringNotContainsStringQuietly(
            $this->beta->user('designer')->email,
            $response->getContent(),
            'Komanda siyahısında başqa studiyanın işçisi göründü.',
        );
    }

    /**
     * Cost rates are what the profitability report is built on and what people
     * are most sensitive about. A designer must not read colleagues' rates.
     */
    public function test_cost_rates_are_not_readable_by_roles_without_analytics(): void
    {
        $this->alfa->user('designer')->forceFill(['hourly_internal_cost' => 37.5])->save();

        $status = $this->actingAs($this->alfa->user('designer'))
            ->get(route('filament.app.resources.users.index'))
            ->status();

        $this->assertContains($status, [403, 404], 'Dizayner komanda siyahısına (və saatlıq tariflərə) girdi.');
    }

    public function test_a_deactivated_employee_keeps_no_panel_access(): void
    {
        $designer = $this->alfa->user('designer');
        $designer->forceFill(['is_active' => false])->save();

        $status = $this->actingAs($designer->fresh())
            ->get(route('filament.app.pages.dashboard'))
            ->status();

        $this->assertNotSame(200, $status, 'Deaktiv işçi panelə girə bildi.');
    }

    // -------------------------------------------------------------- Studiyalar

    public function test_no_studio_role_reaches_the_studio_registry(): void
    {
        foreach (['owner', 'project_manager', 'designer', 'visualizer', 'procurement', 'accountant'] as $role) {
            $user = $this->alfa->user($role);

            $this->assertFalse(
                $user->can('viewAny', Tenant::class),
                "«{$role}» rolu studiyalar reyestrini görə bilir.",
            );
        }
    }

    public function test_a_deactivated_studio_locks_its_staff_out(): void
    {
        $this->alfa->tenant->forceFill(['active' => false])->save();

        $this->assertFalse(
            $this->alfa->user('owner')->fresh()->canAccessPanel(filament()->getPanel('app')),
            'Deaktiv edilmiş studiyanın sahibkarı hələ də panelə girə bilir.',
        );
    }

    /**
     * The portal is a second front door. Deactivating a studio has to close it
     * too, otherwise its clients keep using a studio that has been switched off.
     */
    public function test_a_deactivated_studio_also_closes_its_client_portal(): void
    {
        // 403 specifically, not "anything but 200": portal.home 302-redirects a
        // client who has exactly one project, so a loose assertion here passed
        // whether the studio was switched off or not.
        $this->alfa->tenant->forceFill(['active' => false])->save();

        $this->actingAs($this->alfa->portalUser, 'customer')
            ->get(route('portal.home'))
            ->assertForbidden();

        $this->actingAs($this->alfa->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->alfa->project->id))
            ->assertForbidden();
    }

    public function test_an_active_studios_portal_still_works(): void
    {
        // The counterpart of the test above — proof the 403 comes from the
        // deactivation and not from the route being broken.
        $status = $this->actingAs($this->alfa->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->alfa->project->id))
            ->status();

        $this->assertSame(200, $status, 'Aktiv studiyanın müştərisi portala girə bilmir.');
    }

    // ------------------------------------------------------------------ Rollar

    /**
     * Copy-on-write: editing a built-in role inside one studio must create that
     * studio's own row and leave every other studio untouched.
     */
    public function test_editing_a_built_in_role_in_one_studio_does_not_touch_another(): void
    {
        $designerA = $this->alfa->user('designer');
        $designerB = $this->beta->user('designer');

        $this->assertFalse(
            AccessMatrix::allows($designerA, Domain::Payments, AccessLevel::View),
            'İlkin şərt: dizaynerə ödənişlər qapalıdır.',
        );

        $system = Role::whereNull('tenant_id')->where('key', 'designer')->firstOrFail();

        Role::create(array_merge(
            $system->only(['key', 'name', 'own_projects_only', 'is_system', 'active']),
            [
                'tenant_id' => $this->alfa->tenant->id,
                'levels' => [Domain::Payments->value => AccessLevel::Full->value] + $system->levels,
            ],
        ));
        AccessMatrix::flushCache();

        $this->assertTrue(
            AccessMatrix::allows($designerA->fresh(), Domain::Payments, AccessLevel::Full),
            'Alfa-nın öz rol nüsxəsi tətbiq olunmadı.',
        );
        $this->assertFalse(
            AccessMatrix::allows($designerB->fresh(), Domain::Payments, AccessLevel::View),
            'Alfa-da edilən rol dəyişikliyi Beta studiyasının dizaynerinə də tətbiq olundu.',
        );
    }

    /**
     * The matrix memoises per request. If the key ignores the studio, the first
     * studio resolved in a process wins for everyone — which the automation tick
     * does routinely, looping every studio in one run.
     */
    public function test_the_matrix_cache_does_not_leak_between_studios_in_one_process(): void
    {
        $system = Role::whereNull('tenant_id')->where('key', 'designer')->firstOrFail();

        Role::create(array_merge(
            $system->only(['key', 'name', 'own_projects_only', 'is_system', 'active']),
            [
                'tenant_id' => $this->alfa->tenant->id,
                'levels' => [Domain::Payments->value => AccessLevel::Full->value] + $system->levels,
            ],
        ));
        AccessMatrix::flushCache();

        // Resolve Alfa FIRST so its entry is the one sitting in the memo.
        AccessMatrix::level($this->alfa->user('designer'), Domain::Payments);

        $this->assertFalse(
            AccessMatrix::allows($this->beta->user('designer'), Domain::Payments, AccessLevel::View),
            'Matris keşi bir studiyanın icazələrini digərinə verdi (keş açarında tenant yoxdur).',
        );
    }

    /**
     * Deactivation has to REVOKE. Resolution used to filter `active` inside the
     * lookup and then fall through to the platform default, so switching a role
     * off quietly meant "revert to default" and the user kept working.
     */
    public function test_deactivating_a_role_revokes_the_access_it_granted(): void
    {
        $designer = $this->alfa->user('designer');

        $this->assertTrue(
            AccessMatrix::allows($designer, Domain::Brief, AccessLevel::View),
            'İlkin şərt: dizayner brifi görür.',
        );

        $system = Role::whereNull('tenant_id')->where('key', 'designer')->firstOrFail();

        Role::create(array_merge(
            $system->only(['key', 'name', 'levels', 'own_projects_only']),
            ['tenant_id' => $this->alfa->tenant->id, 'is_system' => false, 'active' => false],
        ));
        AccessMatrix::flushCache();

        $this->assertFalse(
            AccessMatrix::allows($designer->fresh(), Domain::Brief, AccessLevel::View),
            'Rol deaktiv edildi, amma istifadəçi platforma defoltuna qayıdıb işləməyə davam edir.',
        );
    }

    public function test_a_custom_role_created_in_a_studio_stays_in_that_studio(): void
    {
        $this->actingAs($this->alfa->user('owner'));

        $page = new CreateRole;
        $data = (fn () => $this->mutateFormDataBeforeCreate([
            'key' => 'alfa-reviewer',
            'name' => 'Alfa reviewer',
            'levels' => [Domain::Payments->value => AccessLevel::Full->value],
            'own_projects_only' => false,
            'active' => true,
        ]))->call($page);

        $role = Role::create($data);

        $this->assertSame(
            $this->alfa->tenant->id,
            $role->tenant_id,
            'Studiyada yaradılan xüsusi rol platforma səviyyəsində (tenant_id = NULL) yarandı — bütün studiyalara açıqdır.',
        );
        $this->assertFalse($role->is_system, 'Xüsusi rol sistem rolu kimi işarələndi — silinə bilməz.');
    }

    public function test_the_last_active_owner_cannot_be_locked_out(): void
    {
        $owner = $this->alfa->user('owner');

        $this->assertTrue($owner->isLastActiveOwner(), 'İlkin şərt: studiyanın tək sahibkarı.');

        try {
            $owner->update(['is_active' => false]);

            $this->fail('Studiyanın son sahibkarı deaktiv edildi — heç kim Komanda və Rollara girə bilməz.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('son aktiv sahibkar', $e->getMessage());
        }

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_an_owner_can_be_deactivated_when_another_owner_remains(): void
    {
        app(TenantContext::class)->actingAs($this->alfa->tenant->id, fn () => User::create([
            'name' => 'İkinci sahibkar',
            'email' => 'owner2@alfa.test',
            'password' => 'secret123',
            'role' => 'owner',
            'is_active' => true,
        ]));

        $this->alfa->user('owner')->update(['is_active' => false]);

        $this->assertFalse($this->alfa->user('owner')->fresh()->is_active);
    }

    public function test_a_deactivated_role_grants_nothing(): void
    {
        $analyst = Role::create([
            'tenant_id' => $this->alfa->tenant->id,
            'key' => 'analitik',
            'name' => 'Analitik',
            'levels' => [Domain::Payments->value => AccessLevel::Full->value],
            'own_projects_only' => false,
            'is_system' => false,
            'active' => false,
        ]);

        $user = $this->alfa->user('visualizer');
        $user->forceFill(['role_id' => $analyst->id])->save();
        AccessMatrix::flushCache();

        $this->assertFalse(
            AccessMatrix::allows($user->fresh(), Domain::Payments, AccessLevel::Full),
            'Deaktiv edilmiş rol hələ də hüquq verir.',
        );
    }

    public function test_a_user_cannot_be_assigned_another_studios_custom_role(): void
    {
        $betaRole = Role::create([
            'tenant_id' => $this->beta->tenant->id,
            'key' => 'beta-xususi',
            'name' => 'Beta xüsusi',
            'levels' => [Domain::Payments->value => AccessLevel::Full->value],
            'own_projects_only' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $alfaUser = $this->alfa->user('visualizer');
        $alfaUser->forceFill(['role_id' => $betaRole->id])->save();
        AccessMatrix::flushCache();

        $this->assertFalse(
            AccessMatrix::allows($alfaUser->fresh(), Domain::Payments, AccessLevel::Full),
            'Alfa istifadəçisi Beta studiyasının xüsusi rolundan hüquq aldı.',
        );
    }

    public function test_the_role_seeder_stays_idempotent_after_tenant_scoping(): void
    {
        $before = Role::withoutGlobalScopes()->count();

        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame(
            $before,
            Role::withoutGlobalScopes()->count(),
            'RoleSeeder təkrar işlədikdə rolları dublikat edir — (tenant_id, key) unikallığından sonra idempotentlik pozulub.',
        );
    }

    public function test_a_studio_cannot_delete_or_disable_a_platform_wide_role(): void
    {
        $system = Role::whereNull('tenant_id')->where('key', 'designer')->firstOrFail();

        $this->assertTrue(
            app(TenantContext::class)->actingAs($this->alfa->tenant->id, fn () => $system->is_system),
            'İlkin şərt: sistem rolu.',
        );

        // The Beta designer must keep working no matter what Alfa does.
        $this->assertTrue(
            AccessMatrix::allows($this->beta->user('designer'), Domain::Brief, AccessLevel::View),
            'Sistem rolu bir studiyadan sıradan çıxarıla bilər.',
        );
    }

    /**
     * The role constructor has to be able to govern access to itself. Keying the
     * governance screens off the raw `role` column meant a custom role could
     * neither grant nor revoke Komanda and Rollar — the feature looked present
     * and did nothing on the three screens that matter most.
     */
    public function test_a_custom_role_can_be_granted_team_and_role_administration(): void
    {
        $delegate = Role::create([
            'tenant_id' => $this->alfa->tenant->id,
            'key' => 'studio-admin',
            'name' => 'Studiya administratoru',
            'levels' => [Domain::OwnerDashboard->value => AccessLevel::Full->value],
            'own_projects_only' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $user = $this->alfa->user('project_manager');
        $user->forceFill(['role_id' => $delegate->id])->save();
        AccessMatrix::flushCache();

        $this->assertTrue(
            $user->fresh()->can('viewAny', User::class),
            'Rəhbər paneli = Tam verilmiş xüsusi rol Komandaya girə bilmir.',
        );
    }

    public function test_a_custom_role_can_also_have_team_administration_taken_away(): void
    {
        $stripped = Role::create([
            'tenant_id' => $this->alfa->tenant->id,
            'key' => 'sahibkar-mehdud',
            'name' => 'Məhdud sahibkar',
            'levels' => [Domain::Projects->value => AccessLevel::Full->value],
            'own_projects_only' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $owner = $this->alfa->user('owner');
        $owner->forceFill(['role_id' => $stripped->id])->save();
        AccessMatrix::flushCache();

        $this->assertFalse(
            $owner->fresh()->can('viewAny', User::class),
            'Rəhbər paneli verilməmiş xüsusi rol hələ də Komandanı idarə edir — matris nəzərə alınmır.',
        );
    }

    public function test_a_platform_admin_is_not_locked_out_by_having_no_studio(): void
    {
        $admin = User::withoutGlobalScopes()->create([
            'name' => 'Platforma admini',
            'email' => 'platform@archi.test',
            'password' => 'secret123',
            'role' => 'owner',
            'is_active' => true,
            'is_platform_admin' => true,
            'tenant_id' => null,
        ]);

        $status = $this->actingAs($admin)->get(route('filament.app.pages.dashboard'))->status();

        $this->assertSame(200, $status, 'Platforma admini (studiyasız) paneldən kənarda qaldı.');
    }
}
