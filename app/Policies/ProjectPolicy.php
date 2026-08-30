<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Project;
use App\Models\User;
use App\Support\AccessMatrix;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user->role, Domain::Projects, AccessLevel::View);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->allowsOn($user, $project, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        // Owner and PM (matrix level Full) may open projects.
        return AccessMatrix::allows($user->role, Domain::Projects, AccessLevel::Full);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->allowsOn($user, $project, AccessLevel::Edit);
    }

    public function delete(User $user, Project $project): bool
    {
        // TZ: critical operation — owner, or the PM of this very project.
        return $user->isOwner()
            || ($this->allowsOn($user, $project, AccessLevel::Full));
    }

    public function restore(User $user, Project $project): bool
    {
        return $this->delete($user, $project);
    }

    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }

    /**
     * Matrix level + "own projects only" membership scoping (TZ §5.4 "Öz").
     */
    private function allowsOn(User $user, Project $project, AccessLevel $minimum): bool
    {
        if (! AccessMatrix::allows($user->role, Domain::Projects, $minimum)) {
            return false;
        }

        if (AccessMatrix::requiresOwnProject($user->role)) {
            return $project->hasMember($user);
        }

        return true;
    }
}
