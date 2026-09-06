<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
        if ($this->enabledMap === null) {
            $this->enabledMap = AutomationRule::query()->pluck('enabled', 'code')
                ->map(fn ($v) => (bool) $v)
                ->all();
        }

        return $this->enabledMap[$code] ?? false;
    }

    /**
     * Run $fn at most once per (rule, key). Returns true if it ran, false if the
     * key was already claimed. The unique index on automation_runs is the lock.
     */
    public function once(string $ruleCode, string $key, Closure $fn): bool
    {
        try {
            DB::table('automation_runs')->insert([
                'rule_code' => $ruleCode,
                'dedup_key' => $key,
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            return false; // Duplicate key — already handled.
        }

        $fn();

        return true;
    }

    /** Drop the request cache after a rule is toggled in the same request. */
    public function flush(): void
    {
        $this->enabledMap = null;
    }
}
