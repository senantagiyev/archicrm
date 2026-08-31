<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;

class PaymentPolicy
{
    use ScopesProjectDomain;

    protected function domain(): Domain
    {
        return Domain::Payments;
    }

    public function viewAny(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::View);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->allowsOn($user, $payment->project, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Full);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $this->allowsOn($user, $payment->project, AccessLevel::Full);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->allowsOn($user, $payment->project, AccessLevel::Full);
    }
}
