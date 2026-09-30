<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\BriefQuestion;
use App\Models\User;
use App\Support\AccessMatrix;

/**
 * Brif sualının iki mənşəyi var və qaydalar ona görə ayrılır:
 *
 *  • SİSTEM bankının sualı (şablon `tenant_id = null`) — mətn, tip və şərti
 *    məntiq git-dədir (`database/seeders/brief/bank.php`). Paneldən yalnız
 *    variant ŞƏKİLLƏRİ dəyişdirilir (`update`), yaratmaq/silmək yoxdur:
 *    yaradılan sətir seeder-in növbəti işləməsində ya silinər, ya da bankla
 *    ziddiyyətə düşərdi.
 *
 *  • FƏRDİ brifin sualı — studiyanın konstruktorda yaratdığı sətir. Tam
 *    studiyanın malıdır: yaradılır, dəyişdirilir, silinir — amma yalnız
 *    SAHİB studiya tərəfindən və Brif = Tam səviyyəsi ilə.
 *
 * Sual layihəyə aid deyil, ona görə layihə üzvlüyü yoxlanılmır.
 */
class BriefQuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Brief, AccessLevel::View);
    }

    public function view(User $user, BriefQuestion $question): bool
    {
        return $this->viewAny($user) && $this->visibleTo($user, $question);
    }

    public function update(User $user, BriefQuestion $question): bool
    {
        return $this->visibleTo($user, $question)
            && AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full);
    }

    /**
     * Sətir yaratmaq yalnız fərdi brifin içində mümkündür; hansı şablona
     * yazılacağını relation manager sahib şablondan götürür və orada ayrıca
     * yoxlayır. Burada yalnız səviyyə və studiya bağlılığı.
     */
    public function create(User $user): bool
    {
        return $user->tenant_id !== null
            && AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full);
    }

    public function delete(User $user, BriefQuestion $question): bool
    {
        return $this->ownsCustom($user, $question)
            && AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full);
    }

    public function restore(User $user, BriefQuestion $question): bool
    {
        return false;
    }

    public function forceDelete(User $user, BriefQuestion $question): bool
    {
        return false;
    }

    /** Sistem sualı hamıya, fərdi sual yalnız sahibinə. */
    private function visibleTo(User $user, BriefQuestion $question): bool
    {
        $template = $question->template();

        return $template === null || $template->isSystem() || $this->ownsCustom($user, $question);
    }

    private function ownsCustom(User $user, BriefQuestion $question): bool
    {
        $template = $question->template();

        return $template !== null
            && $template->isCustom()
            && $user->tenant_id !== null
            && (int) $template->tenant_id === (int) $user->tenant_id;
    }
}
