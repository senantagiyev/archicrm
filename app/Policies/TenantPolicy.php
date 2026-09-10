<?php

namespace App\Policies;

use App\Enums\StaffRole;
use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isPlatformAdmin($user);
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $this->isPlatformAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isPlatformAdmin($user);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->isPlatformAdmin($user);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return false;
    }

    public function restore(User $user, Tenant $tenant): bool
    {
        return false;
    }

    public function forceDelete(User $user, Tenant $tenant): bool
    {
        return false;
    }

    private function isPlatformAdmin(User $user): bool
    {
        return $user->role === StaffRole::Owner
            && (bool) $user->getAttribute('is_platform_admin');
    }
}
