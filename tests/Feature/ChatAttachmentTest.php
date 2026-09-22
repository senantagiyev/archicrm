<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Çatın Roomix səviyyəsinə qaldırılması fayl, səs və axtarış gətirdi — hər
 * üçü yeni hücum səthidir. Ən vacibi: əlavələr `public` diskindədir, yəni
 * `Storage::url()` linki sessiya tələb etmir. Ona görə portal onları yalnız
 * avtorizasiyalı marşrutla verir və bu testlər həmin sərhədi qoruyur.
 */
class ChatAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private ChatMessage $foreignMessage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->studio = StudioWorld::make('chatfile');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            // Eyni studiyanın BAŞQA müştərisinin lenti — kirayəçi filtri bunu tutmur.
            $this->foreignMessage = ChatMessage::create([
                'project_id' => $this->studio->otherProject->id,
                'author_type' => 'user',
                'author_id' => $this->studio->user('owner')->id,
                'body' => null,
                'attachment_path' => 'chat/'.$this->studio->otherProject->id.'/gizli.pdf',
                'attachment_name' => 'gizli.pdf',
                'attachment_mime' => 'application/pdf',
                'attachment_size' => 1024,
                'kind' => ChatMessage::KIND_FILE,
            ]);
        });

        Storage::disk('public')->put($this->foreignMessage->attachment_path, 'gizli məzmun');
    }

    /** ƏN VACİB: yad layihənin əlavəsi heç bir halda verilmir. */
    public function test_an_attachment_of_another_clients_project_is_not_served(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat.attachment', [$this->studio->otherProject->id, $this->foreignMessage->id]))
            ->assertNotFound();
    }

    /**
     * Mesaj id-si URL-dən gəlir: öz layihəsinin marşrutuna yad mesajın id-sini
     * yazmaq da işləməməlidir (IDOR).
     */
    public function test_a_foreign_message_id_on_an_own_project_route_is_not_found(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat.attachment', [$this->studio->project->id, $this->foreignMessage->id]))
            ->assertNotFound();
    }

    public function test_a_file_only_message_can_be_sent_without_any_text(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), [
                'attachment' => UploadedFile::fake()->create('plan.pdf', 64, 'application/pdf'),
            ]);

        $response->assertOk();

        $message = ChatMessage::findOrFail($response->json('id'));

        $this->assertNull($message->body, 'Fayl-yalnız mesajda mətn boş qalmalıdır.');
        $this->assertSame(ChatMessage::KIND_FILE, $message->kind);
        $this->assertSame('plan.pdf', $message->attachment_name);
        $this->assertTrue($message->hasAttachment());
        Storage::disk('public')->assertExists($message->attachment_path);
    }

    public function test_the_owner_can_download_their_own_attachment(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), [
                'attachment' => UploadedFile::fake()->create('plan.pdf', 8, 'application/pdf'),
            ]);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat.attachment', [$this->studio->project->id, $response->json('id')]))
            ->assertOk();
    }

    public function test_a_voice_message_is_stored_with_the_voice_kind(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), [
                'kind' => ChatMessage::KIND_VOICE,
                'attachment' => UploadedFile::fake()->create('voice.webm', 32, 'audio/webm'),
            ]);

        $response->assertOk();

        $message = ChatMessage::findOrFail($response->json('id'));

        $this->assertSame(ChatMessage::KIND_VOICE, $message->kind);
        $this->assertTrue($message->isVoice());
    }

    /** Aktiv məzmun (HTML/SVG) audio kimi təqdim olunsa belə keçməməlidir. */
    public function test_an_executable_file_is_rejected(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), [
                'attachment' => UploadedFile::fake()->create('shell.php', 4, 'application/x-php'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    /** Səs marşrutuna sənəd, sənəd marşrutuna səs qoymaq olmaz. */
    public function test_a_pdf_cannot_be_smuggled_in_as_a_voice_message(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), [
                'kind' => ChatMessage::KIND_VOICE,
                'attachment' => UploadedFile::fake()->create('plan.pdf', 8, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_an_empty_message_without_text_or_file_is_rejected(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), [], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_the_search_returns_only_the_matching_messages(): void
    {
        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            ChatMessage::create([
                'project_id' => $this->studio->project->id,
                'author_type' => 'client_user',
                'author_id' => $this->studio->portalUser->id,
                'body' => 'Mətbəxin renderini gözləyirəm.',
            ]);

            ChatMessage::create([
                'project_id' => $this->studio->project->id,
                'author_type' => 'client_user',
                'author_id' => $this->studio->portalUser->id,
                'body' => 'Yataq otağı üçün ayrıca sual var.',
            ]);
        });

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat', $this->studio->project).'?q=Mətbəx');

        $response->assertOk();
        $response->assertSee('Mətbəxin renderini gözləyirəm.', false);
        $response->assertDontSee('Yataq otağı üçün ayrıca sual var.', false);
    }

    /** Yad lentin mesajı axtarışa düşməməlidir — sorğu layihə ilə bağlıdır. */
    public function test_the_search_never_reaches_another_projects_messages(): void
    {
        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            ChatMessage::create([
                'project_id' => $this->studio->otherProject->id,
                'author_type' => 'user',
                'author_id' => $this->studio->user('owner')->id,
                'body' => 'Yad layihənin məxfi qeydi.',
            ]);
        });

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat', $this->studio->project).'?q=məxfi')
            ->assertOk()
            ->assertDontSee('Yad layihənin məxfi qeydi.', false);
    }

    /** `%` süzgəci sındırıb bütün lenti çəkməməlidir. */
    public function test_a_wildcard_query_does_not_dump_the_whole_thread(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat', $this->studio->project).'?q=%25')
            ->assertOk()
            ->assertDontSee($this->studio->chatMessage->body, false);
    }

    public function test_a_guest_cannot_download_a_chat_attachment(): void
    {
        $this->get(route('portal.chat.attachment', [$this->studio->otherProject->id, $this->foreignMessage->id]))
            ->assertRedirect(route('portal.login'));
    }

    /** Xam disk yolu heç vaxt səhifəyə düşmür — yalnız avtorizasiyalı marşrut. */
    public function test_the_raw_storage_path_is_never_exposed(): void
    {
        $send = $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project), [
                'attachment' => UploadedFile::fake()->create('plan.pdf', 8, 'application/pdf'),
            ]);

        $message = ChatMessage::findOrFail($send->json('id'));

        $poll = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat.poll', $this->studio->project));

        $poll->assertOk();
        $poll->assertJsonFragment([
            'url' => route('portal.chat.attachment', [$this->studio->project->id, $message->id]),
        ]);
        // Disk yolu (`chat/…`) cavabda ümumiyyətlə olmamalıdır.
        $this->assertStringNotContainsString(
            $message->attachment_path,
            json_encode($poll->json(), JSON_UNESCAPED_SLASHES),
        );
    }
}
