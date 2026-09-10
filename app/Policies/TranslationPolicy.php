<?php

namespace App\Policies;

use App\Models\Translation;
use App\Models\User;

class TranslationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function view(User $user, Translation $translation): bool
    {
        return $user->isPlatformAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function update(User $user, Translation $translation): bool
    {
        return $user->isPlatformAdmin();
    }

    public function delete(User $user, Translation $translation): bool
    {
        return $user->isPlatformAdmin();
    }

    public function restore(User $user, Translation $translation): bool
    {
        return false;
    }

    public function forceDelete(User $user, Translation $translation): bool
    {
        return false;
    }
}
