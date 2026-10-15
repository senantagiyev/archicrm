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
        return $this->allowsOnMaterial($user, $version, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Edit);
    }

    public function update(User $user, DeliverableVersion $version): bool
    {
        return $this->allowsOnMaterial($user, $version, AccessLevel::Edit);
    }

    public function delete(User $user, DeliverableVersion $version): bool
    {
        return $this->allowsOnMaterial($user, $version, AccessLevel::Edit);
    }

    /**
     * Silinmiş (soft-delete) materialın versiyası «layihəsiz qeyd» kimi oxunmamalıdır:
     * `allowsOn(null, null)` domen səviyyəsi ilə icazə verir və layihə üzvlüyü
     * yoxlanmırdı — yəni yad layihənin silinmiş materialının faylı açılardı.
     */
    private function allowsOnMaterial(User $user, DeliverableVersion $version, AccessLevel $minimum): bool
    {
        $deliverable = $version->deliverable;

        if ($deliverable === null) {
            return false;
        }

        return $this->allowsOn($user, $deliverable->project, $minimum, $deliverable->project_id);
    }
}
