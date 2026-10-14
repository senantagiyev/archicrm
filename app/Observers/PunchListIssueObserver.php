<?php

namespace App\Observers;

use App\Models\PunchListIssue;
use App\Notifications\PunchIssueAssigned;

/**
 * Məsul şəxs seçiləndə və ya dəyişəndə ona bildiriş gedir — TaskObserver-dəki
 * tapşırıq təyinatı ilə eyni qayda. Bu olmadan təyin olunan adam qüsurdan
 * xəbər tutmurdu: siyahı yalnız layihənin içindəki tabda idi.
 *
 * `created` və `updated` ayrıdır, `saved` + `wasRecentlyCreated` yox: həmin
 * bayraq eyni obyektdə yaradılışdan sonra da `true` qalır, yəni yaradılmış
 * qüsurun sonrakı İSTƏNİLƏN dəyişikliyi (məs. status) məsul şəxsə təkrar
 * «sizə təyin edildi» göndərərdi.
 */
class PunchListIssueObserver
{
    public function created(PunchListIssue $issue): void
    {
        $this->notifyAssignee($issue);
    }

    public function updated(PunchListIssue $issue): void
    {
        if ($issue->wasChanged('responsible_user_id')) {
            $this->notifyAssignee($issue);
        }
    }

    private function notifyAssignee(PunchListIssue $issue): void
    {
        if (! $issue->responsible_user_id) {
            return;
        }

        // Məsul dəyişibsə köhnə əlaqə yaddaşda qala bilər — təzədən oxunur.
        $issue->load('responsible');
        $issue->loadMissing('project');

        // Deaktiv hesaba getmir (məktubda layihə adı var, adam onsuz da heç nə
        // edə bilməz); özünü məsul seçən də özünə bildiriş almır.
        if ($issue->responsible?->is_active && $issue->responsible_user_id !== auth()->id()) {
            $issue->responsible->notify(new PunchIssueAssigned($issue));
        }
    }
}
