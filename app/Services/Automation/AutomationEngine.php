<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Single authority for whether an automation is active (TZ §8.21). Domain code and
 * scheduled scanners call isEnabled() before producing an effect, so the Admin →
 * Avtomatlaşdırmalar toggle is real — no deploy needed to switch a rule off.
 *
 * once() is the idempotency guard for triggers that can re-fire (external events,
 * repeated scheduler ticks): the effect runs only if a (rule, key) row can be
 * inserted; a duplicate insert is swallowed, so the action never runs twice.
 */
class AutomationEngine
{
    /** @var array<string,bool>|null Request-scoped cache of code => enabled. */
    private ?array $enabledMap = null;

    public function isEnabled(string $code): bool
    {
        if (! config('automations.enabled')) {
            return false;
        }

        if ($this->enabledMap === null) {
            $tenantId = app(TenantContext::class)->id();

            // Platform-wide defaults first, then the studio's own overrides on
            // top. A single global table meant one studio's owner could switch
            // off another studio's notifications.
            $this->enabledMap = AutomationRule::query()
                ->whereNull('tenant_id')
                ->pluck('enabled', 'code')
                ->map(fn ($v) => (bool) $v)
                ->all();

            if ($tenantId !== null) {
                foreach (AutomationRule::query()->where('tenant_id', $tenantId)->pluck('enabled', 'code') as $ruleCode => $enabled) {
                    $this->enabledMap[$ruleCode] = (bool) $enabled;
                }
            }
        }

        return $this->enabledMap[$code] ?? false;
    }

    /**
     * Run $fn at most once per (rule, key). Returns true if it ran, false if the
     * key was already claimed. The unique index on automation_runs is the lock.
     */
    public function once(string $ruleCode, string $key, Closure $fn, ?int $tenantId = null): bool
    {
        // 0 = "no studio": a real value rather than NULL, so the unique index
        // actually de-duplicates those rows.
        $tenantId ??= app(TenantContext::class)->id() ?? 0;

        try {
            DB::table('automation_runs')->insert([
                'tenant_id' => $tenantId,
                'rule_code' => $ruleCode,
                'dedup_key' => $key,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // Already handled on an earlier tick.
        }
        // Every other QueryException — deadlock, lost connection, disk full —
        // propagates. Swallowing them silently cancelled that hour's reminders
        // and reported it as "already handled".

        try {
            $fn();
        } catch (Throwable $e) {
            // The key is claimed before the effect runs, so a failure here would
            // otherwise lose that notification on every future tick as well.
            DB::table('automation_runs')
                ->where('tenant_id', $tenantId)
                ->where('rule_code', $ruleCode)
                ->where('dedup_key', $key)
                ->delete();

            throw $e;
        }

        return true;
    }

    /** Drop the request cache after a rule is toggled in the same request. */
    public function flush(): void
    {
        $this->enabledMap = null;
    }
}
