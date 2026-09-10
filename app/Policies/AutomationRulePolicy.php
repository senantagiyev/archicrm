<?php

namespace App\Policies;

use App\Models\AutomationRule;
use App\Models\User;

class AutomationRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, AutomationRule $automationRule): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AutomationRule $automationRule): bool
    {
        return $user->isOwner();
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
