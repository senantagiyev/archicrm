<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Brief;
use App\Models\BriefTemplate;
use App\Models\User;
use App\Support\AccessMatrix;

/**
 * Brif şablonları: sistem şablonu hamıya görünür, heç kimə redaktə olunmur;
 * fərdi brif isə yalnız SAHİB studiyaya görünür və Brif = Tam səviyyəsi ilə
 * dəyişdirilir. Studiya ayrımı burada da yoxlanılır (resursun sorğusuna
 * güvənmək kifayət deyil — id ilə birbaşa URL də var).
 */
class BriefTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::Brief, AccessLevel::View);
    }

    public function view(User $user, BriefTemplate $template): bool
    {
        return $this->viewAny($user) && $this->visibleTo($user, $template);
    }

    public function create(User $user): bool
    {
        // Şablon studiyaya bağlanır — studiyası olmayan (platforma) hesab
        // hansı studiyaya yaratsın? Belə hesab üçün konstruktor bağlıdır.
        return $user->tenant_id !== null
            && AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full);
    }

    public function update(User $user, BriefTemplate $template): bool
    {
        return $this->owns($user, $template)
            && AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full);
    }

    /**
     * Sistem şablonunu «redaktə etmək» = studiyanın öz nüsxəsini yaratmaq
     * (copy-on-write). Ortaq sətrə toxunulmur. Studiyada artıq nüsxə varsa,
     * yenisi yaranmır — mövcudu redaktə olunur (orijinal onsuz da kölgədədir).
     */
    public function customize(User $user, BriefTemplate $template): bool
    {
        return $template->isSystem()
            && $user->tenant_id !== null
            && AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full)
            && $template->forkFor($user->tenant_id) === null;
    }

    /**
     * Silmək yalnız heç bir layihəyə göndərilməmiş fərdi brif üçün mümkündür.
     * `briefs.brief_template_id` `nullOnDelete`-dir: istifadə olunan şablon
     * silinsə, müştərinin cavabları yerində qalar, amma sualları itər — admin
     * cavabları oxuya bilməz.
     */
    public function delete(User $user, BriefTemplate $template): bool
    {
        return $this->update($user, $template)
            && Brief::withoutGlobalScopes()->where('brief_template_id', $template->id)->doesntExist();
    }

    public function restore(User $user, BriefTemplate $template): bool
    {
        return false;
    }

    public function forceDelete(User $user, BriefTemplate $template): bool
    {
        return false;
    }

    private function visibleTo(User $user, BriefTemplate $template): bool
    {
        return $template->isSystem() || $this->owns($user, $template);
    }

    private function owns(User $user, BriefTemplate $template): bool
    {
        return $template->isCustom()
            && $user->tenant_id !== null
            && (int) $template->tenant_id === (int) $user->tenant_id;
    }
}
