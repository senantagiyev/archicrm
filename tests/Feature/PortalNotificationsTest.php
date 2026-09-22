<?php

namespace Tests\Feature;

use App\Notifications\AutomationAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Roomix «Notifications» — qlobal bildiriş lenti.
 *
 * Bildirişlər `notifiable_id` ilə kəsilir, tenant scope ilə YOX — ona görə
 * bütün yoxlamalar EYNİ studiyanın ikinci müştərisinə qarşı aparılır: sızma
 * məhz orada baş verərdi.
 */
class PortalNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('bildiris');
    }

    /** Verilən istifadəçi üçün bir database bildirişi yazır. */
    private function notify(object $notifiable, array $data, ?string $readAt = null): string
    {
        $id = (string) Str::uuid();

        $notifiable->notifications()->create([
            'id' => $id,
            'type' => AutomationAlert::class,
            'data' => $data,
            'read_at' => $readAt,
        ]);

        return $id;
    }

    public function test_the_list_never_shows_another_clients_notification(): void
    {
        $this->notify($this->studio->portalUser, [
            'title' => 'Mənim bildirişim',
            'body' => 'Öz layihəm üzrə yenilik',
            'project_id' => $this->studio->project->id,
        ]);

        $this->notify($this->studio->secondPortalUser, [
            'title' => 'Yad bildiriş',
            'body' => 'Başqa müştərinin layihəsi',
            'project_id' => $this->studio->otherProject->id,
        ]);

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.notifications'));

        $response->assertOk();
        $response->assertSee('Mənim bildirişim');
        $response->assertDontSee('Yad bildiriş');
    }

    public function test_marking_all_read_touches_only_the_current_customer(): void
    {
        $mine = $this->notify($this->studio->portalUser, ['title' => 'Oxunmamış'], null);
        $foreign = $this->notify($this->studio->secondPortalUser, ['title' => 'Yad oxunmamış'], null);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.notifications.read-all'))
            ->assertRedirect();

        $this->assertDatabaseMissing('notifications', ['id' => $mine, 'read_at' => null]);
        $this->assertDatabaseHas('notifications', ['id' => $foreign, 'read_at' => null]);
    }

    public function test_an_empty_list_shows_the_empty_state_message(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.notifications'));

        $response->assertOk();
        $response->assertSee(t('portal.no_notifications'));
    }

    public function test_the_filters_split_the_feed_by_the_payload_keys(): void
    {
        $this->notify($this->studio->portalUser, [
            'title' => 'Layihə bildirişi', 'project_id' => $this->studio->project->id,
        ]);
        $this->notify($this->studio->portalUser, [
            'title' => 'Tapşırıq bildirişi',
            'project_id' => $this->studio->project->id,
            'task_id' => $this->studio->task->id,
        ]);
        $this->notify($this->studio->portalUser, ['title' => 'Studiya xəbəri']);

        $expected = [
            'all' => ['Layihə bildirişi', 'Tapşırıq bildirişi', 'Studiya xəbəri'],
            'projects' => ['Layihə bildirişi'],
            'tasks' => ['Tapşırıq bildirişi'],
            'news' => ['Studiya xəbəri'],
        ];

        foreach ($expected as $filter => $visible) {
            $response = $this->actingAs($this->studio->portalUser, 'customer')
                ->get(route('portal.notifications', ['filter' => $filter]));

            $response->assertOk();

            foreach (['Layihə bildirişi', 'Tapşırıq bildirişi', 'Studiya xəbəri'] as $title) {
                in_array($title, $visible, true)
                    ? $response->assertSee($title)
                    : $response->assertDontSee($title);
            }
        }
    }

    public function test_the_page_is_closed_to_guests(): void
    {
        $this->get(route('portal.notifications'))->assertRedirect(route('portal.login'));
        $this->post(route('portal.notifications.read-all'))->assertRedirect(route('portal.login'));
    }
}
