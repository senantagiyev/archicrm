<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Document;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;

class DocumentPolicy
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

    public function view(User $user, Document $document): bool
    {
        return $this->allowsOn($user, $document->project, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Edit);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->allowsOn($user, $document->project, AccessLevel::Edit);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->allowsOn($user, $document->project, AccessLevel::Edit);
    }
}
