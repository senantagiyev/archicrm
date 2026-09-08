<?php

namespace Tests\Feature;

use App\Enums\BriefStatus;
use App\Models\Brief;
use App\Models\BriefQuestion;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Project;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Services\Brief\BriefService;
use Database\Seeders\BriefQuestionBankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Spec Part 15 MVP lifecycle: submit → v1, needs_clarification → v(n+1), approve; Screen 02/№16 rules. */
class BriefLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private ClientUser $clientUser;

    private Project $project;

    private Brief $brief;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(BriefQuestionBankSeeder::class);

        $this->manager = User::create(['name' => 'Menecer', 'email' => 'm@test.az', 'password' => 'secret123', 'role' => 'project_manager']);
        $client = Client::create(['name' => 'Müştəri', 'status' => 'client']);
        $this->clientUser = ClientUser::create(['client_id' => $client->id, 'name' => 'Portal', 'email' => 'p@test.az', 'password' => 'secret123']);
        $this->project = Project::create([
            'client_id' => $client->id, 'name' => 'L', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $this->manager->id,
        ]);
        Stage::create(['project_id' => $this->project->id, 'name' => 'Mərhələ 1', 'position' => 1, 'status' => 'in_progress']);

        $this->brief = app(BriefService::class)->forProject($this->project);
    }

    private function question(string $key): BriefQuestion
    {
        return BriefQuestion::where('key', $key)->firstOrFail();
    }

    private function answer(string $key, mixed $value): void
    {
        $this->brief->answers()->updateOrCreate(
            ['brief_question_id' => $this->question($key)->id, 'brief_room_id' => null],
            ['value' => $value, 'delegated_to_designer' => false, 'answered_at' => now()],
        );
    }

    private function autosave(string $key, mixed $value)
    {
        $q = $this->question($key);

        return $this->actingAs($this->clientUser, 'customer')->patchJson(
            route('portal.brief.autosave', [$this->project->id, $q->brief_section_id]),
            ['question_id' => $q->id, 'value' => $value, 'delegated' => false, 'room_id' => null],
        );
    }

    public function test_submit_creates_v1_and_syncs_crm_and_measurement_task(): void
    {
        $this->answer('contact_phone', '+994501234567');
        $this->answer('project_budget_range', ['min' => 10000, 'max' => 50000, 'currency' => 'AZN']);
        $this->answer('has_measurement_plan', 'no');

        app(BriefService::class)->submit($this->brief, $this->clientUser);
        $this->brief->refresh();

        $this->assertSame(BriefStatus::Submitted, $this->brief->statusEnum());
        $this->assertNotNull($this->brief->submitted_at);
        $this->assertSame(1, $this->brief->current_version);
        $this->assertSame(1, $this->brief->versions()->count());
        $this->assertSame('+994501234567', $this->brief->versions()->first()->snapshot['general']['contact_phone']['value']);

        // 13.3 sync + 13.2 №2 automation
        $this->assertSame('+994501234567', $this->project->client->fresh()->phone);
        $this->assertSame('50000.00', $this->project->fresh()->budget_plan);
        $this->assertTrue(Task::where('project_id', $this->project->id)->where('title', 'Obyektin obmerini sifariş et')->exists());
    }

    public function test_locked_brief_allows_edits_only_on_flagged_questions(): void
    {
        app(BriefService::class)->submit($this->brief, $this->clientUser);

        $this->autosave('object_address', 'Bakı')->assertStatus(403);

        app(BriefService::class)->requestClarification($this->brief, $this->question('object_address'), null, $this->manager, 'Ünvanı dəqiqləşdirin');
        $this->brief->refresh();

        $this->assertSame(BriefStatus::NeedsClarification, $this->brief->statusEnum());
        $this->assertSame(1, $this->brief->openComments()->count());

        $this->autosave('object_address', 'Bakı, Nəsimi')->assertOk();
        $this->autosave('total_area_sqm', 120)->assertStatus(403);
    }

    public function test_answering_clarifications_cuts_new_version_and_resolves(): void
    {
        app(BriefService::class)->submit($this->brief, $this->clientUser);
        app(BriefService::class)->requestClarification($this->brief, $this->question('object_address'), null, $this->manager, 'Ünvan?');

        $this->actingAs($this->clientUser, 'customer')
            ->post(route('portal.brief.clarifications.send', $this->project->id))
            ->assertRedirect(route('portal.brief.sent', $this->project->id));

        $this->brief->refresh();
        $this->assertSame(BriefStatus::Submitted, $this->brief->statusEnum());
        $this->assertSame(0, $this->brief->openComments()->count());
        $this->assertSame(2, $this->brief->current_version);
        $this->assertSame(2, $this->brief->versions()->count());
    }

    public function test_approve_locks_brief(): void
    {
        app(BriefService::class)->submit($this->brief, $this->clientUser);
        app(BriefService::class)->approve($this->brief, $this->manager);

        $this->brief->refresh();
        $this->assertSame(BriefStatus::Approved, $this->brief->statusEnum());
        $this->assertNotNull($this->brief->approved_at);
        $this->assertTrue($this->brief->isLocked());
    }

    public function test_design_area_cannot_exceed_total_area(): void
    {
        $this->autosave('total_area_sqm', 100)->assertOk();
        $this->autosave('design_area_sqm', 150)->assertStatus(422)->assertJson(['ok' => false]);
        $this->autosave('design_area_sqm', 80)->assertOk();

        $this->assertSame([], app(BriefService::class)->validationErrors($this->brief->fresh()));
    }

    public function test_designer_option_is_exclusive_in_multiselect(): void
    {
        $this->autosave('wall_materials', ['paint', 'designer'])->assertOk();

        $stored = $this->brief->answers()->where('brief_question_id', $this->question('wall_materials')->id)->first();
        $this->assertSame(['designer'], $stored->value);
    }
}
