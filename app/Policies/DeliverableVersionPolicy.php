<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\DeliverableVersion;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;

class DeliverableVersionPolicy
{
    use ScopesProjectDomain;

    protected function domain(): Domain
    {
        return Domain::FilesDocuments;
    }

    public function viewAny(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::View);
    }

    public function view(User $user, DeliverableVersion $version): bool
    {
        return $this->allowsOn($user, $version->deliverable?->project, AccessLevel::View, $version->deliverable?->project_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Edit);
    }

    public function update(User $user, DeliverableVersion $version): bool
    {
        return $this->allowsOn($user, $version->deliverable?->project, AccessLevel::Edit, $version->deliverable?->project_id);
    }

    public function delete(User $user, DeliverableVersion $version): bool
    {
        return $this->allowsOn($user, $version->deliverable?->project, AccessLevel::Edit, $version->deliverable?->project_id);
    }
}
