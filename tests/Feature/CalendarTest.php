<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $manager): Project
    {
        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);

        return Project::create([
            'client_id' => $client->id, 'name' => 'Layihə', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $manager->id,
        ]);
    }

    public function test_owner_sees_meeting_in_feed(): void
    {
        $owner = User::create(['name' => 'Sahib', 'email' => 'o@test.az', 'password' => 'secret123', 'role' => 'owner']);
        $manager = User::create(['name' => 'Menecer', 'email' => 'm@test.az', 'password' => 'secret123', 'role' => 'project_manager']);
        $project = $this->project($manager);

        Meeting::create([
            'project_id' => $project->id, 'title' => 'Kickoff', 'starts_at' => now()->addDays(2),
        ]);

        $response = $this->actingAs($owner)->getJson(
            route('calendar.events', ['start' => now()->startOfMonth()->toDateString(), 'end' => now()->endOfMonth()->addMonth()->toDateString()])
        );

        $response->assertOk();
        $this->assertStringContainsString('Kickoff', $response->getContent());
    }

    public function test_non_member_designer_gets_empty_feed(): void
    {
        $manager = User::create(['name' => 'Menecer', 'email' => 'm@test.az', 'password' => 'secret123', 'role' => 'project_manager']);
        $outsider = User::create(['name' => 'Dizayner', 'email' => 'd@test.az', 'password' => 'secret123', 'role' => 'designer']);
        $project = $this->project($manager);

        Meeting::create([
            'project_id' => $project->id, 'title' => 'Kickoff', 'starts_at' => now()->addDays(2),
        ]);

        $response = $this->actingAs($outsider)->getJson(
            route('calendar.events', ['start' => now()->startOfMonth()->toDateString(), 'end' => now()->endOfMonth()->addMonth()->toDateString()])
        );

        $response->assertOk();
        $response->assertExactJson([]);
    }
}
