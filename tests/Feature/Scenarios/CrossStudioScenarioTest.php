<?php

namespace Tests\Feature\Scenarios;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Scenario: two paying studios run on the same installation. Nobody in Studio B
 * may see, reach, or change anything belonging to Studio A — through the panel,
 * through a guessed URL, through a polling endpoint, or through the portal.
 *
 * Every assertion goes over real HTTP so the middleware stack, route-model
 * binding and policies are exercised the way a real browser would exercise them.
 */
class CrossStudioScenarioTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alfa;

    private StudioWorld $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = StudioWorld::make('alfa');
        $this->beta = StudioWorld::make('beta');
    }

    public function test_the_two_worlds_are_built_and_distinct(): void
    {
        $this->assertNotSame($this->alfa->tenant->id, $this->beta->tenant->id);
        $this->assertSame($this->alfa->tenant->id, $this->alfa->project->tenant_id);
        $this->assertSame($this->beta->tenant->id, $this->beta->project->tenant_id);
        $this->assertCount(6, $this->alfa->staff);
    }

    public function test_studio_b_owner_cannot_open_a_studio_a_project_in_the_panel(): void
    {
        $response = $this->actingAs($this->beta->user('owner'))
            ->get(route('filament.app.resources.projects.edit', ['record' => $this->alfa->project->id]));

        $this->assertContains($response->status(), [403, 404], 'Studio B-nin sahibkarı Studio A layihəsini açdı.');
    }

    public function test_studio_b_owner_project_list_never_shows_studio_a_projects(): void
    {
        $response = $this->actingAs($this->beta->user('owner'))
            ->get(route('filament.app.resources.projects.index'));

        $response->assertOk();

        // assertDontSee would dump the whole Filament page into the failure output;
        // a plain boolean keeps the report readable.
        $this->assertStringNotContainsStringQuietly(
            $this->alfa->project->name,
            $response->getContent(),
            'Studio B-nin layihə siyahısında Studio A-nın layihəsi göründü.',
        );
    }

    public function test_studio_b_owner_cannot_open_a_studio_a_client_or_task(): void
    {
        $user = $this->beta->user('owner');

        $client = $this->actingAs($user)
            ->get(route('filament.app.resources.clients.edit', ['record' => $this->alfa->client->id]));
        $this->assertContains($client->status(), [403, 404]);

        $task = $this->actingAs($user)
            ->get(route('filament.app.resources.tasks.edit', ['record' => $this->alfa->task->id]));
        $this->assertContains($task->status(), [403, 404]);
    }

    /**
     * The owner is the dangerous role here, not the PM: `requiresOwnProject()` is
     * false for owners, so ProjectPolicy::view returns true for ANY project it is
     * handed — and route-model binding hands it a foreign one unscoped.
     */
    public function test_studio_b_owner_cannot_read_studio_a_project_chat(): void
    {
        $response = $this->actingAs($this->beta->user('owner'))
            ->getJson(route('staff.chat.poll', ['project' => $this->alfa->project->id]));

        $this->assertNotSame(200, $response->status(), 'Studio B Studio A çatını oxudu.');
        $this->assertStringNotContainsStringQuietly(
            $this->alfa->chatMessage->body,
            $response->getContent(),
            'Studio A-nın çat mesajı Studio B-yə qaytarıldı.',
        );
    }

    public function test_studio_b_owner_cannot_write_into_studio_a_project_chat(): void
    {
        $this->actingAs($this->beta->user('owner'))
            ->postJson(route('staff.chat.send', ['project' => $this->alfa->project->id]), [
                'body' => 'Bura girməməliyəm',
            ]);

        $this->assertDatabaseMissing('chat_messages', ['body' => 'Bura girməməliyəm']);
    }

    public function test_calendar_feed_is_scoped_to_the_viewers_studio(): void
    {
        $response = $this->actingAs($this->beta->user('owner'))
            ->getJson(route('calendar.events', ['start' => now()->subMonth()->toDateString(), 'end' => now()->addMonths(3)->toDateString()]));

        $response->assertOk();

        $this->assertStringNotContainsStringQuietly(
            $this->alfa->task->title,
            $response->getContent(),
            'Təqvim Studio A-nın tapşırığını Studio B-yə göstərdi.',
        );
    }

    public function test_a_portal_customer_of_studio_a_cannot_reach_studio_b_projects(): void
    {
        $customer = $this->alfa->portalUser;
        $foreign = $this->beta->project->id;

        $reads = [
            route('portal.projects.show', $foreign),
            route('portal.brief', $foreign),
            route('portal.brief.summary', $foreign),
            route('portal.documents', $foreign),
            route('portal.payments', $foreign),
            route('portal.approvals', $foreign),
            route('portal.chat', $foreign),
            route('portal.chat.poll', $foreign),
        ];

        foreach ($reads as $url) {
            $response = $this->actingAs($customer, 'customer')->get($url);

            $this->assertContains(
                $response->status(),
                [403, 404],
                "Studio A müştərisi Studio B marşrutunu açdı: {$url} (status {$response->status()})",
            );
        }
    }

    public function test_a_portal_customer_of_studio_a_cannot_write_into_studio_b(): void
    {
        $customer = $this->alfa->portalUser;
        $foreign = $this->beta->project->id;

        $chat = $this->actingAs($customer, 'customer')
            ->post(route('portal.chat.send', $foreign), ['body' => 'Yad studiyaya mesaj']);
        $this->assertContains($chat->status(), [403, 404]);
        $this->assertDatabaseMissing('chat_messages', ['body' => 'Yad studiyaya mesaj']);

        $decide = $this->actingAs($customer, 'customer')
            ->post(route('portal.approvals.decide', $this->beta->approval->id), [
                'decision' => 'approved',
            ]);
        $this->assertContains($decide->status(), [403, 404]);
        $this->assertDatabaseHas('approvals', [
            'id' => $this->beta->approval->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_portal_customer_cannot_download_a_foreign_studios_document(): void
    {
        $response = $this->actingAs($this->alfa->portalUser, 'customer')
            ->get(route('portal.documents.download', [
                'project' => $this->beta->project->id,
                'document' => $this->beta->clientDocument->id,
            ]));

        $this->assertContains($response->status(), [403, 404]);
    }
}
