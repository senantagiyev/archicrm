<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\ProjectFile;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;

class ProjectFilePolicy
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

    public function view(User $user, ProjectFile $file): bool
    {
        return $this->allowsOn($user, $file->project, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Edit);
    }

    public function update(User $user, ProjectFile $file): bool
    {
        return $this->allowsOn($user, $file->project, AccessLevel::Edit);
    }

    public function delete(User $user, ProjectFile $file): bool
    {
        return $this->allowsOn($user, $file->project, AccessLevel::Edit);
    }
}
