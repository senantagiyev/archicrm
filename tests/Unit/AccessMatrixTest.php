<?php

namespace Tests\Unit;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Enums\StaffRole;
use App\Support\AccessMatrix;
use PHPUnit\Framework\TestCase;

/**
 * Validates the canonical built-in matrix (the const source seeded into `roles`).
 * Runtime resolution (custom roles, DB fallback) is covered by CustomRoleTest.
 */
class AccessMatrixTest extends TestCase
{
    private function levels(StaffRole $role): array
    {
        return AccessMatrix::systemRoles()[$role->value]['levels'];
    }

    public function test_owner_has_full_access_everywhere(): void
    {
        $levels = $this->levels(StaffRole::Owner);
        foreach (Domain::cases() as $domain) {
            $this->assertSame(AccessLevel::Full->value, $levels[$domain->value]);
        }
    }

    public function test_accountant_cannot_touch_brief_but_owns_finance(): void
    {
        $levels = $this->levels(StaffRole::Accountant);
        $this->assertSame(AccessLevel::None->value, $levels[Domain::Brief->value]);
        $this->assertSame(AccessLevel::Full->value, $levels[Domain::Payments->value]);
        $this->assertSame(AccessLevel::Full->value, $levels[Domain::Budget->value]);
    }

    public function test_visualizer_has_no_finance_access(): void
    {
        $levels = $this->levels(StaffRole::Visualizer);
        $this->assertSame(AccessLevel::None->value, $levels[Domain::Budget->value]);
        $this->assertSame(AccessLevel::None->value, $levels[Domain::Payments->value]);
        $this->assertSame(AccessLevel::None->value, $levels[Domain::Clients->value]);
    }

    public function test_own_project_scoping_applies_to_field_roles_only(): void
    {
        $roles = AccessMatrix::systemRoles();
        $this->assertTrue($roles[StaffRole::ProjectManager->value]['own']);
        $this->assertTrue($roles[StaffRole::Designer->value]['own']);
        $this->assertFalse($roles[StaffRole::Owner->value]['own']);
        $this->assertFalse($roles[StaffRole::Accountant->value]['own']);
    }

    public function test_access_levels_are_ordered(): void
    {
        $this->assertTrue(AccessLevel::Full->atLeast(AccessLevel::View));
        $this->assertTrue(AccessLevel::Edit->atLeast(AccessLevel::Edit));
        $this->assertFalse(AccessLevel::View->atLeast(AccessLevel::Edit));
        $this->assertFalse(AccessLevel::None->atLeast(AccessLevel::View));
    }
}
