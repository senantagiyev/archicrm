<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\AutomationRule;
use App\Models\User;
use App\Support\AccessMatrix;

class AutomationRulePolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::OwnerDashboard, AccessLevel::Full);
    }

    public function view(User $user, AutomationRule $automationRule): bool
    {
        return AccessMatrix::allows($user, Domain::OwnerDashboard, AccessLevel::Full);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AutomationRule $automationRule): bool
    {
        return AccessMatrix::allows($user, Domain::OwnerDashboard, AccessLevel::Full);
    }

    public function delete(User $user, AutomationRule $automationRule): bool
    {
        return false;
    }

    public function restore(User $user, AutomationRule $automationRule): bool
    {
        return false;
    }

    public function forceDelete(User $user, AutomationRule $automationRule): bool
    {
        return false;
    }
}
