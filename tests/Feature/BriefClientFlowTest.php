<?php

namespace Tests\Feature;

use App\Enums\BriefStatus;
use App\Models\Brief;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\ChatMessage;
use App\Services\Brief\BriefService;
use App\Support\TenantContext;
use Database\Seeders\BriefQuestionBankSeeder;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Roomix-dən gətirilən müştəri axını: bölmələr arası naviqasiya, dizaynerlə
 * müzakirə və dizaynerin brifi yenidən açması.
 *
 * Bu üç şey brifin CAVABLARINA toxunmur — testlər də məhz onu qoruyur:
 * müzakirə sualı çata gedir, yenidən açma isə cavabları silmir.
 */
class BriefClientFlowTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private Brief $brief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BriefQuestionBankSeeder::class);
        $this->seed(TranslationSeeder::class);

        $this->studio = StudioWorld::make('brief-flow');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $this->brief = app(BriefService::class)->forProject($this->studio->project);
        });
    }

    private function section(string $key): BriefSection
    {
        return BriefSection::where('brief_template_id', $this->brief->brief_template_id)
            ->where('key', $key)
            ->firstOrFail();
    }

    /** Alt panel «Bölmə N / M» və qonşu bölmələrə keçidi göstərməlidir. */
    public function test_the_section_page_exposes_its_position_and_neighbours(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$this->studio->project->id, $this->section('object')->id]));

        $response->assertOk();

        $nav = $response->viewData('nav');

        $this->assertSame(2, $nav['position'], 'Obyekt ikinci bölmədir.');
        $this->assertGreaterThan(2, $nav['total']);
        $this->assertSame('about_you', $nav['prev']['section']->key);
        $this->assertSame('procurement', $nav['next']['section']->key);
    }

    /** Birinci bölmədə «Geri» olmamalıdır — keçəcək yer yoxdur. */
    public function test_the_first_section_has_no_previous_entry(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.brief.section', [$this->studio->project->id, $this->section('about_you')->id]));

        $this->assertSame(1, $response->viewData('nav')['position']);
        $this->assertNull($response->viewData('nav')['prev']);
    }

    public function test_discussing_a_section_posts_a_chat_message_with_a_link_and_leaves_answers_alone(): void
    {
        $section = $this->section('rooms_hub');
        $before = $this->brief->answers()->count();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.discuss', [$this->studio->project->id, $section->id]), [
                'note' => 'Saxlama yerləri bizim üçün ağrılı mövzudur.',
            ])
            ->assertRedirect();

        $message = ChatMessage::where('project_id', $this->studio->project->id)->latest('id')->first();

        $this->assertNotNull($message);
        $this->assertSame($this->studio->portalUser->getMorphClass(), $message->author_type);
        $this->assertStringContainsString('Otaqların tərkibi', $message->body);
        $this->assertStringContainsString('Saxlama yerləri', $message->body);
        $this->assertStringContainsString(
            route('portal.brief.section', [$this->studio->project->id, $section->id]),
            $message->body,
            'Dizayner birbaşa həmin bölməyə keçə bilməlidir.',
        );

        $this->assertSame($before, $this->brief->fresh()->answers()->count(), 'Müzakirə cavabları dəyişməməlidir.');
    }

    /** Qeyd yazılmasa da mesaj getməlidir — mətn sahəsi istəyə bağlıdır. */
    public function test_a_discussion_without_a_note_still_reaches_the_chat(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.discuss', [$this->studio->project->id, $this->section('lighting')->id]))
            ->assertRedirect();

        $this->assertStringContainsString(
            'İşıqlandırma',
            ChatMessage::where('project_id', $this->studio->project->id)->latest('id')->first()->body,
        );
    }

    /** Yad layihənin brifinə müzakirə göndərmək olmaz. */
    public function test_a_customer_cannot_discuss_a_section_of_another_clients_project(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.brief.discuss', [$this->studio->otherProject->id, $this->section('object')->id]))
            ->assertNotFound();

        $this->assertSame(0, ChatMessage::where('project_id', $this->studio->otherProject->id)->count());
    }

    public function test_reopening_a_submitted_brief_unlocks_it_without_losing_answers(): void
    {
        $service = app(BriefService::class);
        $question = BriefQuestion::where('key', 'object_address')->firstOrFail();

        $this->brief->answers()->create([
            'brief_question_id' => $question->id,
            'brief_room_id' => null,
            'value' => 'Bakı, Nizami 1',
            'answered_at' => now(),
        ]);

        $this->brief->forceFill(['status' => BriefStatus::Submitted->value, 'submitted_at' => now()])->save();
        $this->assertTrue($this->brief->fresh()->isLocked());

        $versionsBefore = $this->brief->versions()->count();

        $service->reopen($this->brief->fresh(), $this->studio->user('owner'), 'Otaqlar dəyişir');

        $brief = $this->brief->fresh();

        $this->assertFalse($brief->isLocked());
        $this->assertSame(BriefStatus::InProgress, $brief->statusEnum());
        $this->assertNull($brief->submitted_at);
        $this->assertSame('Bakı, Nizami 1', $service->valuesByKey($brief)['object_address']);
        $this->assertSame(
            $versionsBefore + 1,
            $brief->versions()->count(),
            'Yenidən açılma da versiya tarixçəsinə düşməlidir.',
        );
    }

    /** Açıq dəqiqləşdirmə sorğuları bağlanmalıdır — yoxsa iki rejim qarışır. */
    public function test_reopening_closes_pending_clarification_requests(): void
    {
        $service = app(BriefService::class);
        $question = BriefQuestion::where('key', 'object_address')->firstOrFail();

        $this->brief->forceFill(['status' => BriefStatus::Submitted->value, 'submitted_at' => now()])->save();
        $service->requestClarification($this->brief, $question, null, $this->studio->user('owner'), 'Ünvanı dəqiqləşdirin');

        $this->assertSame(1, $this->brief->fresh()->openComments()->count());

        $service->reopen($this->brief->fresh(), $this->studio->user('owner'));

        $this->assertSame(0, $this->brief->fresh()->openComments()->count());
    }
}
