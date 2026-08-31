<?php

namespace App\Policies\Concerns;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Project;
use App\Models\User;
use App\Support\AccessMatrix;

/**
 * Shared authorization for project-owned records (budget lines, procurement,
 * payments, documents, files). Enforces the AccessMatrix domain level AND
 * own-project membership for field roles — the server-side control the audit
 * flagged as missing on the finance relation managers.
 */
trait ScopesProjectDomain
{
    abstract protected function domain(): Domain;

    protected function allowsAny(User $user, AccessLevel $minimum): bool
    {
        return AccessMatrix::allows($user->role, $this->domain(), $minimum);
    }

    protected function allowsOn(User $user, ?Project $project, AccessLevel $minimum): bool
    {
        if (! AccessMatrix::allows($user->role, $this->domain(), $minimum)) {
            return false;
        }

        if ($project && AccessMatrix::requiresOwnProject($user->role)) {
            return $project->hasMember($user);
        }

        return true;
    }
}
