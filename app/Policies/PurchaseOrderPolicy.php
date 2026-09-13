<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\Concerns\ScopesProjectDomain;
use App\Support\AccessMatrix;

class PurchaseOrderPolicy
{
    use ScopesProjectDomain;

    protected function domain(): Domain
    {
        return Domain::Procurement;
    }

    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Procurement, AccessLevel::View);
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->allowsOn($user, $purchaseOrder->project, AccessLevel::View, $purchaseOrder->project_id);
    }

    public function create(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Procurement, AccessLevel::Edit);
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->allowsOn($user, $purchaseOrder->project, AccessLevel::Edit, $purchaseOrder->project_id);
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->allowsOn($user, $purchaseOrder->project, AccessLevel::Full, $purchaseOrder->project_id);
    }

    public function restore(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->delete($user, $purchaseOrder);
    }

    public function forceDelete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return false;
    }
}
