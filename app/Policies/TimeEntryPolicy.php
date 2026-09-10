<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\AccessMatrix;

class TimeEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::StagesTasks, AccessLevel::View);
    }

    public function view(User $user, TimeEntry $timeEntry): bool
    {
        return $this->allowsOn($user, $timeEntry, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::StagesTasks, AccessLevel::Edit);
    }

    public function update(User $user, TimeEntry $timeEntry): bool
    {
        return $this->allowsOn($user, $timeEntry, AccessLevel::Edit);
    }

    public function delete(User $user, TimeEntry $timeEntry): bool
    {
        return $this->allowsOn($user, $timeEntry, AccessLevel::Full);
    }

    public function restore(User $user, TimeEntry $timeEntry): bool
    {
        return $this->delete($user, $timeEntry);
    }

    public function forceDelete(User $user, TimeEntry $timeEntry): bool
    {
        return false;
    }

    private function allowsOn(User $user, TimeEntry $timeEntry, AccessLevel $minimum): bool
    {
        if (! AccessMatrix::allows($user, Domain::StagesTasks, $minimum)) {
            return false;
        }

        return ! AccessMatrix::requiresOwnProject($user) || $timeEntry->user_id === $user->id;
    }
}
