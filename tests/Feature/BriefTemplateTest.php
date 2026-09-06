<?php

namespace Tests\Feature;

use App\Models\BriefTemplate;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\Brief\BriefService;
use Database\Seeders\BriefQuestionBankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BriefTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $user = User::create(['name' => 'M', 'email' => 'm@test.az', 'password' => 'secret123', 'role' => 'owner']);
        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);

        return Project::create([
            'client_id' => $client->id, 'name' => 'L', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);
    }

    public function test_seeder_creates_two_templates(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $this->assertSame(2, BriefTemplate::count());
        $this->assertSame('residential', BriefTemplate::default()->key);
    }

    public function test_new_brief_gets_default_template_and_scoped_sections(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $brief = app(BriefService::class)->forProject($this->project());

        $this->assertNotNull($brief->brief_template_id);

        $keys = app(BriefService::class)->sectionMap($brief)
            ->map(fn ($e) => $e['section']->key)->all();

        $this->assertContains('about_you', $keys);   // residential section present
        $this->assertNotContains('com_object', $keys); // commercial section hidden
    }

    public function test_switching_template_changes_visible_sections(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);
        $service = app(BriefService::class);
        $brief = $service->forProject($this->project());

        $commercial = BriefTemplate::where('key', 'commercial')->first();
        $brief->update(['brief_template_id' => $commercial->id]);

        $keys = $service->sectionMap($brief->fresh())
            ->map(fn ($e) => $e['section']->key)->all();

        $this->assertContains('com_object', $keys);
        $this->assertNotContains('about_you', $keys);
    }
}
