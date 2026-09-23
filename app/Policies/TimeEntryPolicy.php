<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\AccessMatrix;

class TimeEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::StagesTasks, AccessLevel::View);
    }

    public function view(User $user, TimeEntry $timeEntry): bool
    {
        return $this->allowsOn($user, $timeEntry, AccessLevel::View);
    }

    /**
     * @param  int|null  $forUserId  qeydin yazılacağı işçi; Filament «Yarat»
     *                               düyməsi üçün boş gəlir (hələ heç kim
     *                               seçilməyib), formanın validasiyası isə onu
     *                               həmişə ötürür
     */
    public function create(User $user, ?int $forUserId = null): bool
    {
        if (! AccessMatrix::allows($user, Domain::StagesTasks, AccessLevel::Edit)) {
            return false;
        }

        return $forUserId === null || $this->mayLogFor($user, $forUserId);
    }

    /**
     * Başqasının adına saat yazmaq səlahiyyəti.
     *
     * Niyə matrisdən oxunur: siyahı da (`TimeEntryResource::getEloquentQuery`)
     * məhz `requiresOwnProject` ilə `user_id`-yə görə daralır. Sahiblik yalnız
     * view/update/delete-də yoxlanıldığı üçün dizayner vizualizatorun adına
     * 8 saat (400 AZN) yaza bilirdi, sonra həmin qeydi nə görür, nə silə bilirdi
     * — yəni yad işçiyə maya dəyəri yazılır və geri alına bilmirdi. İndi
     * yalnız matrisdə «yalnız öz layihələri» qeydi OLMAYAN rollar (sahibkar,
     * mühasib) başqasının adına yaza bilər.
     */
    public function mayLogFor(User $user, int $forUserId): bool
    {
        return ! AccessMatrix::requiresOwnProject($user) || $forUserId === $user->id;
    }

    public function update(User $user, TimeEntry $timeEntry): bool
    {
        return $this->allowsOn($user, $timeEntry, AccessLevel::Edit);
    }

    public function delete(User $user, TimeEntry $timeEntry): bool
    {
        return $this->allowsOn($user, $timeEntry, AccessLevel::Full);
    }

    public function restore(User $user, TimeEntry $timeEntry): bool
    {
        return $this->delete($user, $timeEntry);
    }

    public function forceDelete(User $user, TimeEntry $timeEntry): bool
    {
        return false;
    }

    private function allowsOn(User $user, TimeEntry $timeEntry, AccessLevel $minimum): bool
    {
        if (! AccessMatrix::allows($user, Domain::StagesTasks, $minimum)) {
            return false;
        }

        return ! AccessMatrix::requiresOwnProject($user) || $timeEntry->user_id === $user->id;
    }
}
