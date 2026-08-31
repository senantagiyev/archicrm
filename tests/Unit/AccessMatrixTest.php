<?php

namespace Tests\Unit;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Enums\StaffRole;
use App\Support\AccessMatrix;
use PHPUnit\Framework\TestCase;

class AccessMatrixTest extends TestCase
{
    public function test_owner_has_full_access_everywhere(): void
    {
        foreach (Domain::cases() as $domain) {
            $this->assertSame(AccessLevel::Full, AccessMatrix::level(StaffRole::Owner, $domain));
        }
    }

    public function test_accountant_cannot_touch_brief_but_owns_finance(): void
    {
        $this->assertSame(AccessLevel::None, AccessMatrix::level(StaffRole::Accountant, Domain::Brief));
        $this->assertSame(AccessLevel::Full, AccessMatrix::level(StaffRole::Accountant, Domain::Payments));
        $this->assertSame(AccessLevel::Full, AccessMatrix::level(StaffRole::Accountant, Domain::Budget));
    }

    public function test_visualizer_has_no_finance_access(): void
    {
        $this->assertSame(AccessLevel::None, AccessMatrix::level(StaffRole::Visualizer, Domain::Budget));
        $this->assertSame(AccessLevel::None, AccessMatrix::level(StaffRole::Visualizer, Domain::Payments));
        $this->assertSame(AccessLevel::None, AccessMatrix::level(StaffRole::Visualizer, Domain::Clients));
    }

    public function test_own_project_scoping_applies_to_field_roles_only(): void
    {
        $this->assertTrue(AccessMatrix::requiresOwnProject(StaffRole::ProjectManager));
        $this->assertTrue(AccessMatrix::requiresOwnProject(StaffRole::Designer));
        $this->assertFalse(AccessMatrix::requiresOwnProject(StaffRole::Owner));
        $this->assertFalse(AccessMatrix::requiresOwnProject(StaffRole::Accountant));
    }

    public function test_access_levels_are_ordered(): void
    {
        $this->assertTrue(AccessLevel::Full->atLeast(AccessLevel::View));
        $this->assertTrue(AccessLevel::Edit->atLeast(AccessLevel::Edit));
        $this->assertFalse(AccessLevel::View->atLeast(AccessLevel::Edit));
        $this->assertFalse(AccessLevel::None->atLeast(AccessLevel::View));
    }
}
