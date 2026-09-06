<?php

namespace Tests\Feature;

use App\Enums\DecisionSource;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectDecision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_decision_can_be_created_with_casts(): void
    {
        $user = User::create([
            'name' => 'Menecer', 'email' => 'm@test.az', 'password' => 'secret123',
            'role' => 'designer',
        ]);

        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);

        $project = Project::create([
            'client_id' => $client->id, 'name' => 'Test layihə', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);

        $decision = ProjectDecision::create([
            'project_id' => $project->id,
            'title' => 'Mətbəx rəngi seçildi',
            'category' => 'Dizayn',
            'source' => DecisionSource::Meeting,
            'decision' => 'Ağ mat fasad təsdiqləndi.',
            'made_by_user_id' => $user->id,
            'decided_at' => now(),
            'client_approved' => true,
            'notes' => 'Görüşdə razılaşdırıldı.',
        ]);

        $this->assertDatabaseHas('project_decisions', [
            'project_id' => $project->id,
            'title' => 'Mətbəx rəngi seçildi',
            'source' => 'meeting',
            'client_approved' => true,
        ]);

        $fresh = $decision->fresh();

        $this->assertSame(DecisionSource::Meeting, $fresh->source);
        $this->assertTrue($fresh->client_approved);
        $this->assertSame($project->id, $fresh->project->id);
        $this->assertSame($user->id, $fresh->madeBy->id);
    }

    public function test_source_defaults_and_client_approved_default_false(): void
    {
        $client = Client::create(['name' => 'Müştəri 2', 'status' => 'client']);

        $project = Project::create([
            'client_id' => $client->id, 'name' => 'Layihə 2', 'type' => 'apartment',
            'status' => 'active',
        ]);

        $decision = ProjectDecision::create([
            'project_id' => $project->id,
            'title' => 'İlkin qərar',
            'decision' => 'Manual qeyd.',
        ]);

        $fresh = $decision->fresh();

        $this->assertSame(DecisionSource::Manual, $fresh->source);
        $this->assertFalse($fresh->client_approved);
    }
}
