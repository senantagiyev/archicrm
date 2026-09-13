<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Meeting;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;

class MeetingPolicy
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

    public function view(User $user, Meeting $meeting): bool
    {
        return $this->allowsOn($user, $meeting->project, AccessLevel::View, $meeting->project_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Edit);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $this->allowsOn($user, $meeting->project, AccessLevel::Edit, $meeting->project_id);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $this->allowsOn($user, $meeting->project, AccessLevel::Full, $meeting->project_id);
    }

    public function restore(User $user, Meeting $meeting): bool
    {
        return $this->delete($user, $meeting);
    }

    public function forceDelete(User $user, Meeting $meeting): bool
    {
        return false;
    }
}
