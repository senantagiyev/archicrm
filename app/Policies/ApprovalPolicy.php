<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Enums\ProjectStatus;
use App\Models\Approval;
use App\Models\Project;
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
 * Decisions are the client's and are made in the portal, so there is no update
 * or delete here.
 *
 * Yaratmaq — müştəriyə sorğu GÖNDƏRMƏKDİR. Əvvəl `create()` həmişə `false`
 * idi və «Razılaşdırmalar» bölməsində düymə yox idi: sorğu yalnız smeta,
 * komplektasiya və dizayn cədvəllərinin içindən gedirdi, müştəri isə
 * «razılaşdırmanı harada yaradım» deyə soruşurdu. İndi layihədə Redaktə +
 * Sənədlərdə Redaktə (forma faylı sənəd kimi yaradır) + siyahını görmək
 * tələb olunur: sahibkar, menecer və dizayner — öz layihələrində.
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
        return $this->viewAny($user)
            && $this->allowsAny($user, AccessLevel::Edit)
            && AccessMatrix::allows($user, Domain::FilesDocuments, AccessLevel::Edit);
    }

    /**
     * Konkret layihəyə sorğu göndərmək: «yalnız öz layihəsi» rolu üçün üzvlük
     * şərtdir. Arxivlənmiş layihədə müştəri qərar verə bilmir (portal 403
     * qaytarır), ona görə orada yeni sorğu da açılmır.
     */
    public function createFor(User $user, Project $project): bool
    {
        return $this->create($user)
            && $project->status !== ProjectStatus::Archived
            && $this->allowsOn($user, $project, AccessLevel::Edit, $project->id);
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
