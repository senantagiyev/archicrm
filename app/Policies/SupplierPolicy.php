<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AccessMatrix;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Procurement, AccessLevel::View);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Procurement, AccessLevel::Edit);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return AccessMatrix::allows($user, Domain::Procurement, AccessLevel::Full);
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return $this->delete($user, $supplier);
    }

    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return false;
    }
}
