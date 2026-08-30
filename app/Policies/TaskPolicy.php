<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessMatrix;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user->role, Domain::StagesTasks, AccessLevel::View);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->allowsOn($user, $task, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return AccessMatrix::allows($user->role, Domain::StagesTasks, AccessLevel::Edit);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->allowsOn($user, $task, AccessLevel::Edit);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->allowsOn($user, $task, AccessLevel::Full)
            || $user->id === $task->author_user_id;
    }

    private function allowsOn(User $user, Task $task, AccessLevel $minimum): bool
    {
        if (! AccessMatrix::allows($user->role, Domain::StagesTasks, $minimum)) {
            return false;
        }

        if (AccessMatrix::requiresOwnProject($user->role)) {
            $task->loadMissing('project');

            return $user->id === $task->assignee_user_id || $task->project->hasMember($user);
        }

        return true;
    }
}
