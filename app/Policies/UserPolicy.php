<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\User;
use App\Support\AccessMatrix;

/**
 * Managing the team is a governance right, read from the access matrix rather
 * than from the raw `role` column. Keying off the enum meant the role
 * constructor could neither grant nor revoke access to Komanda — a custom role
 * with everything switched off still managed staff, and one built to delegate
 * staff administration could not.
 *
 * `OwnerDashboard = Tam` is the matrix's "runs the studio" level: in the
 * built-in matrix only the owner holds it, so behaviour is unchanged for the
 * six standard roles.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->governs($user);
    }

    public function view(User $user, User $model): bool
    {
        return $this->governs($user) || $user->is($model);
    }

    public function create(User $user): bool
    {
        return $this->governs($user);
    }

    public function update(User $user, User $model): bool
    {
        // Same studio only. The global scope already hides other studios' users,
        // but a policy that returns true for any user is a missing second line
        // of defence, not a safe default.
        return $this->governs($user) && $user->tenant_id === $model->tenant_id;
    }

    public function delete(User $user, User $model): bool
    {
        return $this->update($user, $model) && ! $user->is($model);
    }

    public function restore(User $user, User $model): bool
    {
        return $this->update($user, $model);
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    private function governs(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::OwnerDashboard, AccessLevel::Full);
    }
}
