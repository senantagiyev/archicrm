<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\ProcurementItem;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;

class ProcurementItemPolicy
{
    use ScopesProjectDomain;

    protected function domain(): Domain
    {
        return Domain::Procurement;
    }

    public function viewAny(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::View);
    }

    public function view(User $user, ProcurementItem $item): bool
    {
        return $this->allowsOn($user, $item->project, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Edit);
    }

    public function update(User $user, ProcurementItem $item): bool
    {
        return $this->allowsOn($user, $item->project, AccessLevel::Edit);
    }

    public function delete(User $user, ProcurementItem $item): bool
    {
        // TZ §5.10: an approved + paid item can never be deleted — only cancelled.
        if ($item->isDeletionLocked()) {
            return false;
        }

        return $this->allowsOn($user, $item->project, AccessLevel::Full);
    }
}
