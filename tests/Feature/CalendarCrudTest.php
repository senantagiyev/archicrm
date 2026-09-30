<?php

namespace Tests\Feature;

use App\Filament\Pages\Calendar;
use App\Models\Meeting;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Təqvimdə görüş CRUD-u: yaratma (düymə və ya boş günə klik), redaktə,
 * silmə — təqvimdən çıxmadan. Əvvəl təqvim yalnız oxumaq üçün idi.
 */
class CalendarCrudTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('calcrud');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_a_meeting_is_created_updated_and_deleted_from_the_calendar(): void
    {
        $this->asStaff('owner');
        $project = $this->studio->project;

        // Boş günə klik: tarix əvvəlcədən doldurulur.
        Livewire::test(Calendar::class)
            ->assertActionVisible('createMeeting')
            ->mountAction('createMeeting', ['date' => '2026-10-15'])
            ->assertActionDataSet(['starts_at' => '2026-10-15 10:00'])
            ->setActionData(['project_id' => $project->id, 'title' => 'Obyektə baxış', 'starts_at' => '2026-10-15 10:00:00', 'ends_at' => '2026-10-15 11:00:00'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertDispatched('calendar-refresh');

        $meeting = Meeting::where('title', 'Obyektə baxış')->firstOrFail();
        $this->assertSame($project->id, $meeting->project_id);

        // Təqvim lenti görüşü id ilə qaytarır — JS redaktə modalını onunla açır.
        $events = $this->getJson(route('calendar.events', ['start' => '2026-10-01', 'end' => '2026-10-31']))->assertOk()->json();
        $event = collect($events)->firstWhere('extendedProps.meetingId', $meeting->id);
        $this->assertNotNull($event, 'Görüş təqvimdə görünməlidir.');

        // Redaktə.
        Livewire::test(Calendar::class)
            ->mountAction('editMeeting', ['meeting' => $meeting->id])
            ->assertActionDataSet(['title' => 'Obyektə baxış'])
            ->setActionData(['title' => 'Obyektə baxış (köçürüldü)', 'starts_at' => '2026-10-16 12:00:00', 'ends_at' => '2026-10-16 13:00:00'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $meeting->refresh();
        $this->assertSame('Obyektə baxış (köçürüldü)', $meeting->title);
        $this->assertSame('2026-10-16 12:00', $meeting->starts_at->format('Y-m-d H:i'));

        // Silmə — modalın «Sil» düyməsi.
        Livewire::test(Calendar::class)
            ->callAction('editMeeting', arguments: ['meeting' => $meeting->id, 'delete' => true])
            ->assertHasNoActionErrors();

        $this->assertNull(Meeting::find($meeting->id));
    }

    public function test_validation_rejects_an_end_before_the_start(): void
    {
        $this->asStaff('owner');

        Livewire::test(Calendar::class)
            ->callAction('createMeeting', data: [
                'project_id' => $this->studio->project->id,
                'title' => 'Səhv vaxt',
                'starts_at' => '2026-10-15 12:00:00',
                'ends_at' => '2026-10-15 11:00:00',
            ])
            ->assertHasActionErrors(['ends_at']);

        $this->assertSame(0, Meeting::where('title', 'Səhv vaxt')->count());
    }

    public function test_an_own_projects_role_cannot_open_a_foreign_projects_meeting(): void
    {
        $foreign = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => Meeting::create([
            'project_id' => $this->studio->otherProject->id,
            'title' => 'Yad layihənin görüşü',
            'starts_at' => now()->addDay(),
        ]));

        $this->asStaff('designer');

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Calendar::class)->mountAction('editMeeting', ['meeting' => $foreign->id]);
    }

    public function test_a_view_only_role_sees_no_create_button(): void
    {
        // Mühasib: Layihələr = Baxış — görüş yarada bilmir.
        $accountant = $this->asStaff('accountant');

        if ($accountant->can('create', Meeting::class)) {
            $this->markTestSkipped('Mühasibin matrisdə görüş yaratmaq hüququ var.');
        }

        Livewire::test(Calendar::class)->assertActionHidden('createMeeting');
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
