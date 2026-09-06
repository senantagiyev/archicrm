<?php

namespace Tests\Feature;

use App\Models\BriefQuestion;
use App\Models\BriefSection;
use Tests\TestCase;

class BriefV2Test extends TestCase
{
    public function test_skip_logic_shows_and_hides_by_dependency(): void
    {
        $section = new BriefSection(['key' => 's', 'name' => ['az' => 'S']]);

        $q = new BriefQuestion([
            'key' => 'pet_type',
            'type' => 'text',
            'skip_logic' => ['question' => 'has_pet', 'operator' => 'equals', 'value' => '1'],
        ]);

        $this->assertTrue($q->shouldShow(['has_pet' => '1']));
        $this->assertFalse($q->shouldShow(['has_pet' => '0']));
        $this->assertFalse($q->shouldShow([]));

        // No rule → always shown.
        $free = new BriefQuestion(['key' => 'x', 'type' => 'text']);
        $this->assertTrue($free->shouldShow([]));
    }

    public function test_in_and_multiselect_operators(): void
    {
        $in = new BriefQuestion([
            'key' => 'q', 'type' => 'text',
            'skip_logic' => ['question' => 'style', 'operator' => 'in', 'value' => ['modern', 'loft']],
        ]);
        $this->assertTrue($in->shouldShow(['style' => 'loft']));
        $this->assertFalse($in->shouldShow(['style' => 'classic']));

        // equals against a multiselect answer = "contains".
        $contains = new BriefQuestion([
            'key' => 'q2', 'type' => 'text',
            'skip_logic' => ['question' => 'materials', 'operator' => 'equals', 'value' => 'wood'],
        ]);
        $this->assertTrue($contains->shouldShow(['materials' => ['wood', 'stone']]));
        $this->assertFalse($contains->shouldShow(['materials' => ['stone']]));
    }
}
