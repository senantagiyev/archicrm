<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Approval;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;
use App\Support\AccessMatrix;

/**
 * Approvals are polymorphic — the row's label and amount come from a budget line,
 * a procurement item, a stage, a document or a deliverable. Seeing the list is
 * therefore seeing money, so it takes project access AND read access to at least
 * one of the two financial domains. A visualizer (Budget=None, Procurement=None)
 * is out; a designer (View on both) is in, limited to their own projects.
 *
 * Decisions are the client's and are made in the portal, so there is no create,
 * update or delete here.
 */
class ApprovalPolicy
{
    use ScopesProjectDomain;

    protected function domain(): Domain
    {
        return Domain::Projects;
    }

    public function viewAny(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::View) && $this->readsMoney($user);
    }

    public function view(User $user, Approval $approval): bool
    {
        return $this->allowsOn($user, $approval->project, AccessLevel::View, $approval->project_id) && $this->readsMoney($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Approval $approval): bool
    {
        return false;
    }

    public function delete(User $user, Approval $approval): bool
    {
        return false;
    }

    private function readsMoney(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Budget, AccessLevel::View)
            || AccessMatrix::allows($user, Domain::Procurement, AccessLevel::View);
    }
}
