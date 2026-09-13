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
        return AccessMatrix::allows($user, $this->domain(), $minimum);
    }

    /**
     * @param  Project|null  $project  the resolved project, null when missing
     * @param  int|null  $projectId  the record's raw project_id — tells "this
     *                               record has no project" (studio-level data,
     *                               e.g. office overhead or a lump-sum purchase
     *                               order) apart from "its project is archived",
     *                               which must fail closed
     */
    protected function allowsOn(User $user, ?Project $project, AccessLevel $minimum, ?int $projectId = null): bool
    {
        if (! AccessMatrix::allows($user, $this->domain(), $minimum)) {
            return false;
        }

        if (! AccessMatrix::requiresOwnProject($user)) {
            return true;
        }

        // Deliberately unassigned: there is no membership to check, so the domain
        // level alone governs. Treating this as a denial locked the procurement
        // role out of every project-less purchase order — its own module.
        if ($project === null && $projectId === null) {
            return true;
        }

        // Assigned but unreachable (archived project): fail closed rather than
        // silently widening a scoped role.
        return $project !== null && $project->hasMember($user);
    }
}
