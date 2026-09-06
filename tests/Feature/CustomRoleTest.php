<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessMatrix;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AccessMatrix::flushCache(); // static cache persists across tests in one process
    }

    private function user(string $role, ?int $roleId = null): User
    {
        return User::create([
            'name' => 'U', 'email' => $role.'-'.uniqid().'@test.az', 'password' => 'secret123',
            'role' => $role, 'role_id' => $roleId,
        ]);
    }

    public function test_fallback_matrix_without_seeded_roles(): void
    {
        $designer = $this->user('designer');
        $this->assertTrue(AccessMatrix::allows($designer, Domain::Brief, AccessLevel::Full));
        $this->assertFalse(AccessMatrix::allows($designer, Domain::Payments, AccessLevel::View));
        $this->assertTrue(AccessMatrix::requiresOwnProject($designer));

        $owner = $this->user('owner');
        $this->assertTrue(AccessMatrix::allows($owner, Domain::Payments, AccessLevel::Full));
        $this->assertFalse(AccessMatrix::requiresOwnProject($owner));
    }

    public function test_seeded_system_roles_match_const(): void
    {
        $this->seed(RoleSeeder::class);
        AccessMatrix::flushCache();

        $this->assertSame(6, Role::where('is_system', true)->count());

        $designer = $this->user('designer');
        $this->assertTrue(AccessMatrix::allows($designer, Domain::Brief, AccessLevel::Full));
        $this->assertFalse(AccessMatrix::allows($designer, Domain::Payments, AccessLevel::View));
        $this->assertTrue(AccessMatrix::requiresOwnProject($designer));
    }

    public function test_custom_role_overrides_base_enum(): void
    {
        $this->seed(RoleSeeder::class);

        $reviewer = Role::create([
            'key' => 'reviewer',
            'name' => 'Rəyçi',
            'levels' => [
                Domain::Projects->value => 1,   // view
                Domain::Brief->value => 1,       // view
                Domain::Payments->value => 0,
            ],
            'own_projects_only' => true,
            'is_system' => false,
            'active' => true,
        ]);

        // Base enum says designer (Brief=Full), but the custom role must win.
        $user = $this->user('designer', $reviewer->id);
        AccessMatrix::flushCache();

        $this->assertSame(AccessLevel::View, AccessMatrix::level($user, Domain::Brief));
        $this->assertFalse(AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full));
        $this->assertSame(AccessLevel::View, AccessMatrix::level($user, Domain::Projects));
        $this->assertTrue(AccessMatrix::requiresOwnProject($user));
    }
}
