<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Enums\TaskStatus;
use App\Filament\Pages\TaskPlanner;
use App\Models\Role;
use App\Models\Task;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Tapşırıq planı ekranı: studiya izolyasiyası, icazə və filtr.
 *
 * Ən vacibi birincidir — ekran bütün studiyanın işini bir sorğuda yığır, yəni
 * `Tenant` scope-u sızsa, bu səhifə sızmanın ən qısa yoludur.
 */
class TaskPlannerTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alfa;

    private StudioWorld $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->alfa = StudioWorld::make('alfa');
        $this->beta = StudioWorld::make('beta');
    }

    private function makeTask(StudioWorld $world, string $title, array $attributes = []): Task
    {
        return app(TenantContext::class)->actingAs($world->tenant->id, fn () => Task::create(array_merge([
            'project_id' => $world->project->id,
            'stage_id' => $world->stage->id,
            'title' => $title,
            'status' => TaskStatus::Todo->value,
            'assignee_user_id' => $world->user('designer')->id,
        ], $attributes)));
    }

    // ------------------------------------------------------- studiya izolyasiyası

    public function test_another_studios_task_is_not_listed(): void
    {
        $this->makeTask($this->alfa, 'Alfa eskiz planı');
        $this->makeTask($this->beta, 'Beta gizli tapşırıq');

        $response = $this->actingAs($this->alfa->user('owner'))
            ->get(route('filament.app.pages.task-planner'));

        $response->assertOk();
        $response->assertSee('Alfa eskiz planı', false);
        $response->assertDontSee('Beta gizli tapşırıq', false);
    }

    // ---------------------------------------------------------------- icazə

    public function test_a_role_without_the_stages_and_tasks_domain_cannot_open_the_page(): void
    {
        $role = Role::create([
            'tenant_id' => $this->alfa->tenant->id,
            'key' => 'mühasib_koordinator',
            'name' => 'Koordinatorsuz rol',
            'levels' => [
                Domain::Projects->value => AccessLevel::View->value,
                Domain::StagesTasks->value => AccessLevel::None->value,
            ],
            'own_projects_only' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $user = $this->alfa->user('designer');
        $user->forceFill(['role_id' => $role->id])->save();
        AccessMatrix::flushCache();

        $status = $this->actingAs($user->fresh())
            ->get(route('filament.app.pages.task-planner'))
            ->status();

        $this->assertNotSame(200, $status, 'Mərhələ/Tapşırıq icazəsi olmayan rol planlaşdırma ekranına girdi.');
        $this->assertFalse(TaskPlanner::canAccess(), 'Səhifə naviqasiyada da gizlənməlidir.');
    }

    public function test_a_role_with_view_access_can_open_the_page(): void
    {
        $this->actingAs($this->alfa->user('accountant'))
            ->get(route('filament.app.pages.task-planner'))
            ->assertOk();
    }

    // ---------------------------------------------------------------- filtr

    public function test_the_mine_filter_keeps_only_the_current_users_tasks(): void
    {
        $owner = $this->alfa->user('owner');

        $this->makeTask($this->alfa, 'Sahibkarın tapşırığı', ['assignee_user_id' => $owner->id]);
        $this->makeTask($this->alfa, 'Dizaynerin tapşırığı');

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->alfa->tenant->id);

        Livewire::test(TaskPlanner::class)
            ->assertOk()
            ->assertSee('Sahibkarın tapşırığı')
            ->assertSee('Dizaynerin tapşırığı')
            ->set('scope', 'mine')
            ->assertSee('Sahibkarın tapşırığı')
            ->assertDontSee('Dizaynerin tapşırığı');
    }

    /**
     * «Yalnız öz layihələri» rolları üçün «bütün studiya» filtri genişlənmir:
     * planlaşdırıcı üzv olduğu layihənin BÜTÜN tapşırıqlarını göstərir (komanda
     * lövhəsi budur), üzvü olmadığı layihəninkini isə göstərmir.
     *
     * Əvvəl filtr icraçıya baxırdı — nəticədə layihə meneceri öz layihəsinin
     * lövhəsini boş görürdü, üzvü olmadığı layihədə ona təyin edilən tapşırıq isə
     * açıq qalırdı.
     */
    public function test_planner_shows_the_whole_board_of_own_projects_only(): void
    {
        $this->makeTask($this->alfa, 'Dizaynerin tapşırığı');
        $this->makeTask($this->alfa, 'Sahibkarın tapşırığı', [
            'assignee_user_id' => $this->alfa->user('owner')->id,
        ]);

        // Dizayner `otherProject`-in üzvü deyil, tapşırıq isə ona təyin edilib.
        $foreignStage = app(TenantContext::class)->actingAs(
            $this->alfa->tenant->id,
            fn () => $this->alfa->otherProject->stages()->create(['name' => 'Eskiz', 'position' => 1]),
        );

        $this->makeTask($this->alfa, 'Yad layihənin tapşırığı', [
            'project_id' => $this->alfa->otherProject->id,
            'stage_id' => $foreignStage->id,
        ]);

        $response = $this->actingAs($this->alfa->user('designer'))
            ->get(route('filament.app.pages.task-planner'));

        $response->assertOk();
        $response->assertSee('Dizaynerin tapşırığı', false);
        $response->assertSee('Sahibkarın tapşırığı', false);
        $response->assertDontSee('Yad layihənin tapşırığı', false);
    }

    // ------------------------------------------------------- yeni tapşırıq modalı

    public function test_a_task_can_be_created_from_the_modal(): void
    {
        $owner = $this->alfa->user('owner');

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->alfa->tenant->id);

        Livewire::test(TaskPlanner::class)
            ->callAction('newTask', [
                'title' => 'Ölçü götürmə',
                'description' => 'Obyektdə ölçü',
                'project_id' => $this->alfa->project->id,
                'stage_id' => $this->alfa->stage->id,
                'assignee_user_id' => $this->alfa->user('designer')->id,
                'deadline' => now()->addWeek()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $task = Task::where('title', 'Ölçü götürmə')->firstOrFail();

        $this->assertSame($this->alfa->tenant->id, $task->tenant_id);
        $this->assertSame($owner->id, $task->author_user_id);
        $this->assertSame(TaskStatus::Todo, $task->status);
    }

    /** Baxış səviyyəsi yazmağa icazə vermir — düymə gizlədilir, yazma bağlanır. */
    public function test_view_only_access_cannot_create_tasks(): void
    {
        $this->actingAs($this->alfa->user('accountant'));

        $this->assertTrue(TaskPlanner::canAccess());
        $this->assertFalse((new TaskPlanner)->canCreateTasks());
    }

    // ---------------------------------------------------------------- qruplaşma

    public function test_tasks_are_grouped_by_status_and_overdue_ones_are_marked(): void
    {
        $this->makeTask($this->alfa, 'Gecikmiş iş', [
            'assignee_user_id' => $this->alfa->user('owner')->id,
            'deadline' => now()->subWeek(),
        ]);
        $this->makeTask($this->alfa, 'İşdə olan', [
            'assignee_user_id' => $this->alfa->user('owner')->id,
            'status' => TaskStatus::InProgress->value,
        ]);

        $this->actingAs($this->alfa->user('owner'));
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->alfa->tenant->id);

        $page = new TaskPlanner;
        $groups = $page->getGroupedTasks();

        $this->assertContains('Gecikmiş iş', $groups[TaskStatus::Todo->value]->pluck('title')->all());
        $this->assertSame(['İşdə olan'], $groups[TaskStatus::InProgress->value]->pluck('title')->all());
        $this->assertNotContains('İşdə olan', $groups[TaskStatus::Todo->value]->pluck('title')->all());

        $response = $this->get(route('filament.app.pages.task-planner'));
        $response->assertOk();
        $response->assertSee('gecikib', false);
    }
}
