<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Client;
use App\Models\User;
use App\Support\AccessMatrix;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user->role, Domain::Clients, AccessLevel::View);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessMatrix::allows($user->role, Domain::Clients, AccessLevel::Edit);
    }

    public function update(User $user, Client $client): bool
    {
        return AccessMatrix::allows($user->role, Domain::Clients, AccessLevel::Edit);
    }

    public function delete(User $user, Client $client): bool
    {
        return AccessMatrix::allows($user->role, Domain::Clients, AccessLevel::Full);
    }

    public function restore(User $user, Client $client): bool
    {
        return $this->delete($user, $client);
    }

    public function forceDelete(User $user, Client $client): bool
    {
        return false;
    }
}
