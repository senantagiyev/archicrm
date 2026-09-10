<?php

namespace Tests\Feature;

use App\Filament\Resources\TaskResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'active' => true]);
    }

    /** Create a project inside a given tenant's context. */
    private function projectFor(Tenant $tenant, string $name): Project
    {
        return app(TenantContext::class)->actingAs($tenant->id, function () use ($name) {
            $client = Client::create(['name' => $name.' müştəri', 'status' => 'client']);
            $user = User::create(['name' => 'PM', 'email' => uniqid().'@t.az', 'password' => 'secret123', 'role' => 'owner']);

            return Project::create([
                'client_id' => $client->id, 'name' => $name, 'type' => 'apartment',
                'status' => 'active', 'manager_user_id' => $user->id,
            ]);
        });
    }

    public function test_create_stamps_current_tenant(): void
    {
        $a = $this->tenant('alpha');
        $project = $this->projectFor($a, 'Alpha layihə');

        $this->assertSame($a->id, $project->tenant_id);
    }

    public function test_queries_are_isolated_per_tenant(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');

        $pa = $this->projectFor($a, 'Alpha');
        $pb = $this->projectFor($b, 'Beta');

        $ctx = app(TenantContext::class);

        $seenByA = $ctx->actingAs($a->id, fn () => Project::pluck('id')->all());
        $this->assertContains($pa->id, $seenByA);
        $this->assertNotContains($pb->id, $seenByA);

        $seenByB = $ctx->actingAs($b->id, fn () => Project::pluck('id')->all());
        $this->assertContains($pb->id, $seenByB);
        $this->assertNotContains($pa->id, $seenByB);
    }

    public function test_no_context_sees_everything(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $this->projectFor($a, 'Alpha');
        $this->projectFor($b, 'Beta');

        // CLI / tests / queue: no tenant in context → scope inert.
        $this->assertSame(2, Project::count());
    }

    public function test_cross_tenant_record_is_invisible_by_id(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $pa = $this->projectFor($a, 'Alpha');

        $found = app(TenantContext::class)->actingAs($b->id, fn () => Project::find($pa->id));
        $this->assertNull($found);
    }

    public function test_project_member_sees_only_tasks_assigned_to_them(): void
    {
        $tenant = $this->tenant('task-scope');

        app(TenantContext::class)->actingAs($tenant->id, function (): void {
            $owner = User::create(['name' => 'Owner', 'email' => 'owner@scope.az', 'password' => 'secret123', 'role' => 'owner']);
            $designer = User::create(['name' => 'Designer', 'email' => 'designer@scope.az', 'password' => 'secret123', 'role' => 'designer']);
            $other = User::create(['name' => 'Other', 'email' => 'other@scope.az', 'password' => 'secret123', 'role' => 'designer']);
            $client = Client::create(['name' => 'Client', 'status' => 'client']);
            $project = Project::create([
                'client_id' => $client->id, 'name' => 'Project', 'type' => 'apartment',
                'status' => 'active', 'manager_user_id' => $owner->id,
            ]);
            $project->members()->attach([$designer->id, $other->id], ['project_role' => 'designer']);
            $stage = $project->stages()->create(['name' => 'Design', 'position' => 1]);
            $ownTask = Task::create(['project_id' => $project->id, 'stage_id' => $stage->id, 'title' => 'Own', 'status' => 'todo', 'assignee_user_id' => $designer->id]);
            $otherTask = Task::create(['project_id' => $project->id, 'stage_id' => $stage->id, 'title' => 'Other', 'status' => 'todo', 'assignee_user_id' => $other->id]);

            auth()->login($designer);
            $visibleTaskIds = TaskResource::getEloquentQuery()->pluck('id')->all();

            $this->assertSame([$ownTask->id], $visibleTaskIds);
            $this->assertTrue($designer->can('update', $ownTask));
            $this->assertFalse($designer->can('view', $otherTask));
            $this->assertFalse($designer->can('update', $otherTask));
        });
    }
}
