<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\BudgetLine;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;

class BudgetLinePolicy
{
    use ScopesProjectDomain;

    protected function domain(): Domain
    {
        return Domain::Budget;
    }

    public function viewAny(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::View);
    }

    public function view(User $user, BudgetLine $line): bool
    {
        return $this->allowsOn($user, $line->project, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Edit);
    }

    public function update(User $user, BudgetLine $line): bool
    {
        return $this->allowsOn($user, $line->project, AccessLevel::Edit);
    }

    public function delete(User $user, BudgetLine $line): bool
    {
        return $this->allowsOn($user, $line->project, AccessLevel::Edit);
    }
}
