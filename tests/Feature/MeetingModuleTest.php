<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MeetingModuleTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): Project
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'o@test.az', 'password' => 'secret123',
            'role' => 'owner',
        ]);

        $client = Client::create(['name' => 'Müştəri', 'status' => 'lead']);

        return Project::create([
            'client_id' => $client->id, 'name' => 'Test layihə', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);
    }

    public function test_meeting_is_created_with_project_relation_and_datetime_casts(): void
    {
        $project = $this->makeProject();

        $meeting = Meeting::create([
            'project_id' => $project->id,
            'title' => 'Kickoff görüşü',
            'starts_at' => '2026-09-10 14:30:00',
            'ends_at' => '2026-09-10 15:30:00',
            'participants' => ['Anar', 'Leyla'],
            'location' => 'Ofis',
            'online_link' => 'https://meet.example.com/abc',
            'notes' => 'İlk görüş',
        ]);

        $fresh = $meeting->fresh();

        $this->assertTrue($fresh->project->is($project));
        $this->assertInstanceOf(Carbon::class, $fresh->starts_at);
        $this->assertInstanceOf(Carbon::class, $fresh->ends_at);
        $this->assertSame('2026-09-10 14:30:00', $fresh->starts_at->format('Y-m-d H:i:s'));
        $this->assertIsArray($fresh->participants);
        $this->assertSame(['Anar', 'Leyla'], $fresh->participants);
    }

    public function test_meeting_belongs_to_project(): void
    {
        $project = $this->makeProject();
        $meeting = Meeting::create([
            'project_id' => $project->id,
            'title' => 'Status görüşü',
            'starts_at' => now(),
        ]);

        $this->assertSame($project->id, $meeting->project->id);
        $this->assertTrue($project->id === $meeting->fresh()->project_id);
    }
}
