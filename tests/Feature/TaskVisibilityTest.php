<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Pages\TaskPlanner;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Tapşırığı kim görür.
 *
 * Qayda (matris: dizayner/vizualizator/təchizat — «Edit, own»): Tapşırıq
 * domenində TAM səlahiyyəti olmayan işçi yalnız özünə TƏYİN OLUNAN və ya özünün
 * YARATDIĞI tapşırığı görür. Sahibkar və layihə meneceri layihədəki bütün
 * tapşırıqları görür. Əvvəl layihənin istənilən üzvü həmkarlarının bütün
 * tapşırıqlarını siyahıda, planlayıcıda, təqvimdə və birbaşa URL ilə oxuyurdu.
 */
class TaskVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private Task $forDesigner;

    private Task $forProcurement;

    private Task $byDesignerForProcurement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('taskvis');
        $project = $this->studio->project;
        $staff = $this->studio->staff;

        // Hər üçü eyni layihədə — dizayner də, təchizatçı da layihənin üzvüdür.
        $this->forDesigner = $this->task('Dizaynerin işi', $staff['designer'], $staff['project_manager']);
        $this->forProcurement = $this->task('Təchizatçının işi', $staff['procurement'], $staff['project_manager']);
        $this->byDesignerForProcurement = $this->task('Dizaynerin təchizata verdiyi iş', $staff['procurement'], $staff['designer']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_an_employee_sees_only_tasks_assigned_to_or_created_by_them(): void
    {
        $designer = $this->asStaff('designer');

        Livewire::test(ListTasks::class)
            ->assertCanSeeTableRecords([$this->forDesigner, $this->byDesignerForProcurement])
            ->assertCanNotSeeTableRecords([$this->forProcurement]);

        $this->assertTrue($designer->can('view', $this->forDesigner));
        $this->assertTrue($designer->can('view', $this->byDesignerForProcurement), 'Yaratdığı tapşırığı izləyə bilməlidir.');
        $this->assertFalse($designer->can('view', $this->forProcurement), 'Həmkarın tapşırığı açılmamalıdır.');
        $this->assertFalse($designer->can('update', $this->forProcurement));

        // Birbaşa URL.
        // 404 — sətir sorğudan çıxarılır, mövcudluğu belə bildirilmir.
        $this->assertContains($this->get(TaskResource::getUrl('edit', ['record' => $this->forProcurement]))->status(), [403, 404]);
        $this->get(TaskResource::getUrl('edit', ['record' => $this->forDesigner]))->assertOk();
    }

    public function test_the_assignee_sees_a_task_someone_else_created_for_them(): void
    {
        $procurement = $this->asStaff('procurement');

        Livewire::test(ListTasks::class)
            ->assertCanSeeTableRecords([$this->forProcurement, $this->byDesignerForProcurement])
            ->assertCanNotSeeTableRecords([$this->forDesigner]);

        $this->assertFalse($procurement->can('view', $this->forDesigner));
    }

    public function test_the_owner_and_the_project_manager_see_every_task_of_the_project(): void
    {
        foreach (['owner', 'project_manager'] as $role) {
            $user = $this->asStaff($role);

            Livewire::test(ListTasks::class)
                ->assertCanSeeTableRecords([$this->forDesigner, $this->forProcurement, $this->byDesignerForProcurement]);

            $this->assertTrue($user->can('view', $this->forProcurement), $role.' bütün tapşırıqları görməlidir.');
        }
    }

    public function test_the_planner_and_the_calendar_follow_the_same_rule(): void
    {
        $this->asStaff('designer');

        // «Hamısı» görünüşü — `mine` süzgəci olmadan da həmkarın işi düşmür.
        $titles = collect(Livewire::test(TaskPlanner::class)->set('scope', 'all')->instance()->getGroupedTasks())
            ->flatten(1)
            ->map(fn (Task $task) => $task->title)
            ->all();

        $this->assertContains('Dizaynerin işi', $titles);
        $this->assertContains('Dizaynerin təchizata verdiyi iş', $titles);
        $this->assertNotContains('Təchizatçının işi', $titles);

        $events = $this->getJson(route('calendar.events', [
            'start' => now()->subMonth()->toDateString(),
            'end' => now()->addMonth()->toDateString(),
        ]))->assertOk()->json();

        $calendarTitles = collect($events)->pluck('title')->implode(' | ');
        $this->assertStringContainsString('Dizaynerin işi', $calendarTitles);
        $this->assertStringNotContainsString('Təchizatçının işi', $calendarTitles);
    }

    // ═══════════════════════ Köməkçilər ═══════════════════════

    private function task(string $title, User $assignee, User $author): Task
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => Task::create([
            'project_id' => $this->studio->project->id,
            'stage_id' => $this->studio->stage->id,
            'title' => $title,
            'assignee_user_id' => $assignee->id,
            'author_user_id' => $author->id,
            'status' => TaskStatus::Todo->value,
            'priority' => 'normal',
            'deadline' => now()->addDays(2),
        ]));
    }

    private function asStaff(string $role): User
    {
        $user = $this->studio->user($role);

        $this->flushSession();
        while (SupportRedirects::$redirectorCacheStack !== []) {
            app()->instance('redirect', array_pop(SupportRedirects::$redirectorCacheStack));
        }

        AccessMatrix::flushCache();
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->studio->tenant->id);

        return $user;
    }
}
