<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Services\Automation\AutomationEngine;
use Database\Seeders\AutomationRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_enabled_reflects_the_toggle(): void
    {
        AutomationRule::create(['code' => 'rule-x', 'name' => 'On', 'trigger' => 't', 'priority' => 'high', 'enabled' => true]);
        AutomationRule::create(['code' => 'rule-y', 'name' => 'Off', 'trigger' => 't', 'priority' => 'high', 'enabled' => false]);

        $engine = app(AutomationEngine::class);

        $this->assertTrue($engine->isEnabled('rule-x'));
        $this->assertFalse($engine->isEnabled('rule-y'));
        $this->assertFalse($engine->isEnabled('rule-unknown'));
    }

    public function test_global_feature_flag_disables_every_rule(): void
    {
        AutomationRule::create(['code' => 'rule-x', 'name' => 'On', 'trigger' => 't', 'priority' => 'high', 'enabled' => true]);
        config()->set('automations.enabled', false);

        $this->assertFalse(app(AutomationEngine::class)->isEnabled('rule-x'));
    }

    public function test_once_runs_the_effect_exactly_once_per_key(): void
    {
        $engine = app(AutomationEngine::class);
        $runs = 0;

        $first = $engine->once('rule-1', 'entity:5', function () use (&$runs) {
            $runs++;
        });
        $second = $engine->once('rule-1', 'entity:5', function () use (&$runs) {
            $runs++;
        });

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, $runs);

        // A different key is independent.
        $engine->once('rule-1', 'entity:6', function () use (&$runs) {
            $runs++;
        });
        $this->assertSame(2, $runs);
    }

    public function test_seeder_loads_the_full_catalog(): void
    {
        $this->seed(AutomationRuleSeeder::class);

        $this->assertSame(41, AutomationRule::count());
        // Re-seeding preserves an admin's toggle.
        AutomationRule::where('code', 'rule-9')->update(['enabled' => false]);
        $this->seed(AutomationRuleSeeder::class);
        $this->assertFalse(AutomationRule::where('code', 'rule-9')->value('enabled'));
    }
}
