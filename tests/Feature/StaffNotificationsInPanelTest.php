<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskOverdue;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * İşçi bildirişləri panelin zəng ikonunda görünməlidir.
 *
 * Filament-in bildiriş paneli yalnız `data.format = filament` olan sətirləri
 * göstərir. Əvvəl tapşırıq bildirişləri sadə massiv yazırdı — bazada idi, amma
 * heç bir işçi görmürdü.
 */
class StaffNotificationsInPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_notifications_are_stored_in_the_panel_format_with_a_link(): void
    {
        $studio = StudioWorld::make('notif');
        $designer = $studio->user('designer');

        $task = app(TenantContext::class)->actingAs($studio->tenant->id, fn () => Task::create([
            'project_id' => $studio->project->id,
            'stage_id' => $studio->stage->id,
            'title' => 'Ölçmə aparmaq',
            'assignee_user_id' => $designer->id,
            'status' => TaskStatus::Todo->value,
        ]));

        // Tapşırıq yaradılanda təyin bildirişi avtomatik gedir — real axın.
        $this->assertGreaterThan(0, $designer->notifications()->count(), 'Təyin bildirişi avtomatik göndərilməlidir.');
        $designer->notifications()->delete();

        $designer->notifyNow(new TaskAssigned($task));
        $designer->notifyNow(new TaskOverdue($task));

        $rows = $designer->notifications()->get();
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame('filament', $row->data['format'] ?? null, 'Panel yalnız Filament formatlı sətri göstərir.');
            $this->assertStringContainsString('Ölçmə aparmaq', $row->data['body']);
            $this->assertSame($task->id, $row->data['task_id'], 'Köhnə sahələr saxlanılmalıdır.');
            $this->assertStringContainsString('/tasks/'.$task->id.'/edit', $row->data['actions'][0]['url'] ?? '');
        }

        // Filament-in zəng paneli məhz bu sorğu ilə oxuyur.
        $this->assertSame(2, $designer->notifications()->where('data->format', 'filament')->count());
    }
}
