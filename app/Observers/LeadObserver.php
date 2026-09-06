<?php

namespace App\Observers;

use App\Enums\StaffRole;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\AutomationAlert;
use App\Services\Automation\AutomationEngine;

/**
 * Əlavə B rule 1: when a lead is created, assign an owner if none is set and
 * notify the responsible person to start first contact. Gated by the Admin toggle.
 */
class LeadObserver
{
    public function created(Lead $lead): void
    {
        if (! app(AutomationEngine::class)->isEnabled('rule-1')) {
            return;
        }

        if (! $lead->responsible_user_id) {
            $owner = User::query()
                ->where('is_active', true)
                ->where('role', StaffRole::Owner->value)
                ->first();

            if ($owner) {
                // saveQuietly: don't re-fire model events (and re-enter this observer).
                $lead->responsible_user_id = $owner->id;
                $lead->saveQuietly();
            }
        }

        $lead->loadMissing('responsible');
        $lead->responsible?->notify(new AutomationAlert(
            'Yeni lid təyin edildi',
            trim("\"{$lead->first_name} {$lead->last_name}\"").' lidi sizə təyin olundu. İlk əlaqəni planlaşdırın.',
            null,
            ['lead_id' => $lead->id],
            'rule-1',
        ));
    }
}
