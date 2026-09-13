<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;

class ChangeRequestPolicy
{
    use ScopesProjectDomain;

    protected function domain(): Domain
    {
        return Domain::Projects;
    }

    public function viewAny(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::View);
    }

    public function view(User $user, ChangeRequest $record): bool
    {
        return $this->allowsOn($user, $record->project, AccessLevel::View, $record->project_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Edit);
    }

    public function update(User $user, ChangeRequest $record): bool
    {
        return $this->allowsOn($user, $record->project, AccessLevel::Edit, $record->project_id);
    }

    public function delete(User $user, ChangeRequest $record): bool
    {
        return $this->allowsOn($user, $record->project, AccessLevel::Edit, $record->project_id);
    }
}
