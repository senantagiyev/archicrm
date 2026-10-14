<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;

class ChangeRequestPolicy
{
    use ScopesProjectDomain;

    protected function domain(): Domain
    {
        return Domain::Projects;
    }

    public function viewAny(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::View);
    }

    public function view(User $user, ChangeRequest $record): bool
    {
        return $this->allowsOn($user, $record->project, AccessLevel::View, $record->project_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsAny($user, AccessLevel::Edit);
    }

    public function update(User $user, ChangeRequest $record): bool
    {
        return $this->allowsOn($user, $record->project, AccessLevel::Edit, $record->project_id);
    }

    /**
     * Pul və müddət barədə qərar: studiya adından təsdiq və müştərinin cavabının
     * qeydi. «Edit» səviyyəli rollar (dizayner, vizualizator, təchizat) sorğunu
     * aça, təsirini hesablaya və təsdiqə göndərə bilər, amma özləri təsdiqləyə
     * bilməz — əks halda «studiyada təsdiq» mərhələsinin mənası qalmırdı.
     * Matrisdə layihələr üzrə «Full» yalnız sahibkar və layihə menecerindədir.
     */
    public function approve(User $user, ChangeRequest $record): bool
    {
        return $this->allowsOn($user, $record->project, AccessLevel::Full, $record->project_id);
    }

    public function delete(User $user, ChangeRequest $record): bool
    {
        return $this->allowsOn($user, $record->project, AccessLevel::Edit, $record->project_id);
    }
}
