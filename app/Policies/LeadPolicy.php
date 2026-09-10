<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Lead;
use App\Models\User;
use App\Support\AccessMatrix;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Clients, AccessLevel::View);
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Clients, AccessLevel::Edit);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return AccessMatrix::allows($user, Domain::Clients, AccessLevel::Full);
    }

    public function restore(User $user, Lead $lead): bool
    {
        return $this->delete($user, $lead);
    }

    public function forceDelete(User $user, Lead $lead): bool
    {
        return false;
    }
}
