<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\BriefQuestion;
use App\Models\User;
use App\Support\AccessMatrix;

/**
 * Brif sualları QLOBAL kataloqdur — layihəyə aid deyil, bütün studiyaların
 * brifləri eyni banka baxır. Ona görə burada layihə üzvlüyü yoxlanılmır,
 * yalnız Brief domenindəki səviyyə.
 *
 * Suala TAM səlahiyyət yalnız şəkilləri dəyişməyə icazə verir (panel yalnız
 * `options`-u redaktə edir). Sualın mətni, tipi və şərti məntiqi git-dəki
 * bankdadır — onları paneldən dəyişmək mümkün deyil, ona görə `create`,
 * `delete` və `restore` həmişə bağlıdır: yaradılan sətir seeder-in növbəti
 * işləməsində ya silinər, ya da bankla ziddiyyətə düşərdi.
 */
class BriefQuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Brief, AccessLevel::View);
    }

    public function view(User $user, BriefQuestion $question): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, BriefQuestion $question): bool
    {
        return AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, BriefQuestion $question): bool
    {
        return false;
    }

    public function restore(User $user, BriefQuestion $question): bool
    {
        return false;
    }

    public function forceDelete(User $user, BriefQuestion $question): bool
    {
        return false;
    }
}
