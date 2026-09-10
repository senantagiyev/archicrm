<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Expense;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;
use App\Support\AccessMatrix;

class ExpensePolicy
{
    use ScopesProjectDomain;

    protected function domain(): Domain
    {
        return Domain::Payments;
    }

    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Payments, AccessLevel::View);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->allowsOn($user, $expense->project, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Payments, AccessLevel::Edit);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->allowsOn($user, $expense->project, AccessLevel::Edit);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->allowsOn($user, $expense->project, AccessLevel::Full);
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $this->delete($user, $expense);
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }
}
