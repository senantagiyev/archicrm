<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessMatrix;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::StagesTasks, AccessLevel::View);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->allowsOn($user, $task, AccessLevel::View);
    }

    public function create(User $user): bool
    {
        return AccessMatrix::allows($user, Domain::StagesTasks, AccessLevel::Edit);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->allowsOn($user, $task, AccessLevel::Edit);
    }

    public function delete(User $user, Task $task): bool
    {
        if ($this->allowsOn($user, $task, AccessLevel::Full)) {
            return true;
        }

        // «Öz yaratdığım tapşırıq» güzəşti qalır, amma yalnız üzv olduğum
        // layihədə: əks halda 2-ci tapıntı (yad layihənin tapşırığı açıqdır)
        // silmə istiqamətində olduğu kimi qalardı.
        return $user->id === $task->author_user_id
            && $this->belongsToVisibleProject($user, $task);
    }

    /**
     * «Yalnız öz layihələri» rolları üçün hədd LAYİHƏ ÜZVLÜYÜdür, icraçı olmaq yox.
     *
     * Əvvəl burada `assignee_user_id` yoxlanırdı və bu, hər iki istiqamətdə səhv
     * cavab verirdi:
     *  — layihə meneceri matrisdə Mərhələ/Tapşırıq = Tam olmasına baxmayaraq öz
     *    layihəsində dizaynerə verdiyi tapşırığı aça bilmirdi (Attention-dakı
     *    keçid onda 404 verirdi);
     *  — üzvü OLMADIĞI layihənin tapşırığı kiməsə təyin ediləndə isə həmin adam
     *    layihəni aça bilmədiyi halda tapşırığı görüb redaktə edirdi.
     * İndi şərt bütün digər layihə-əsaslı modullarla (ExpenseResource,
     * MeetingResource) eynidir. Silinmiş layihədə `project` null qayıdır —
     * orfan tapşırıq da bağlıdır.
     */
    private function allowsOn(User $user, Task $task, AccessLevel $minimum): bool
    {
        if (! AccessMatrix::allows($user, Domain::StagesTasks, $minimum)) {
            return false;
        }

        return $this->belongsToVisibleProject($user, $task);
    }

    /** Tapşırığın layihəsi istifadəçiyə açıqdırmı (matrisin «öz layihələri» şərti). */
    private function belongsToVisibleProject(User $user, Task $task): bool
    {
        if (! AccessMatrix::requiresOwnProject($user)) {
            return true;
        }

        return $task->project?->hasMember($user) ?? false;
    }
}
