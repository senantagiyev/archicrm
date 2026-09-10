<?php

namespace App\Policies;

use App\Enums\StaffRole;
use App\Models\Translation;
use App\Models\User;

class TranslationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isPlatformAdmin($user);
    }

    public function view(User $user, Translation $translation): bool
    {
        return $this->isPlatformAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isPlatformAdmin($user);
    }

    public function update(User $user, Translation $translation): bool
    {
        return $this->isPlatformAdmin($user);
    }

    public function delete(User $user, Translation $translation): bool
    {
        return $this->isPlatformAdmin($user);
    }

    public function restore(User $user, Translation $translation): bool
    {
        return false;
    }

    public function forceDelete(User $user, Translation $translation): bool
    {
        return false;
    }

    private function isPlatformAdmin(User $user): bool
    {
        return $user->role === StaffRole::Owner
            && (bool) $user->getAttribute('is_platform_admin');
    }
}
