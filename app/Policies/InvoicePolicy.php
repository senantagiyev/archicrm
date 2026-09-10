<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;
use App\Support\AccessMatrix;

class InvoicePolicy
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

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->allowsOn($user, $invoice->project, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Payments, AccessLevel::Edit);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->allowsOn($user, $invoice->project, AccessLevel::Edit);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->allowsOn($user, $invoice->project, AccessLevel::Full);
    }

    public function restore(User $user, Invoice $invoice): bool
    {
        return $this->delete($user, $invoice);
    }

    public function forceDelete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
