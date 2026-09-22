<?php

namespace Tests\Feature;

use App\Models\DiaryEntry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Müəllif nəzarəti gündəliyi müştəriyə dərc olunan məzmundur, ona görə iki şey
 * ayrıca yoxlanılır: qaralama portala sızmır və eyni studiyanın BAŞQA
 * müştərisinin layihəsinə giriş bağlıdır (kirayəçi filtri bunu tutmur).
 */
class DiaryTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private DiaryEntry $published;

    private DiaryEntry $draft;

    private DiaryEntry $foreignEntry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('diary');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $this->published = $this->studio->project->diaryEntries()->create([
                'author_user_id' => $this->studio->user('designer')->id,
                'body' => 'Mətbəxdə kafel işi bitdi.',
                'photos' => ['diary-photos/a.jpg', 'diary-photos/b.jpg'],
                'published_at' => now()->subDay(),
            ]);

            $this->draft = $this->studio->project->diaryEntries()->create([
                'author_user_id' => $this->studio->user('designer')->id,
                'body' => 'Qaralama qeyd, hələ dərc olunmayıb.',
                'photos' => [],
                'published_at' => null,
            ]);

            $this->foreignEntry = $this->studio->otherProject->diaryEntries()->create([
                'author_user_id' => $this->studio->user('owner')->id,
                'body' => 'Yad layihənin qeydi.',
                'photos' => ['diary-photos/c.jpg'],
                'published_at' => now(),
            ]);
        });
    }

    public function test_the_customer_sees_the_published_entry_with_its_photos(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->project));

        $response->assertOk();
        $response->assertSee($this->published->body);
        $response->assertSee($this->studio->user('designer')->name);

        // İki foto — hər biri öz <img> elementi ilə.
        $this->assertSame(
            2,
            substr_count($response->getContent(), 'loading="lazy"'),
            'Dərc olunmuş qeydin foto sayı portalda düzgün göstərilmir.',
        );
        // Şəkil XAM disk yolu ilə deyil, avtorizasiyalı marşrutla verilir —
        // `public` diskinin birbaşa linki sessiya tələb etmir.
        $response->assertSee(route('portal.diary.photo', [$this->studio->project->id, $this->published->id, 0]), false);
        $response->assertSee(route('portal.diary.photo', [$this->studio->project->id, $this->published->id, 1]), false);
        $response->assertDontSee('diary-photos/a.jpg', false);
    }

    public function test_an_unpublished_entry_never_reaches_the_customer(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->project))
            ->assertOk()
            ->assertDontSee($this->draft->body);
    }

    public function test_a_customer_cannot_open_another_clients_project_diary(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->otherProject))
            ->assertNotFound();
    }

    public function test_a_customer_of_another_studio_cannot_open_the_diary(): void
    {
        $other = StudioWorld::make('diary2');

        $this->actingAs($other->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->project))
            ->assertNotFound();
    }

    public function test_the_diary_is_closed_to_guests(): void
    {
        $this->get(route('portal.diary', $this->studio->project))
            ->assertRedirect(route('portal.login'));
    }

    public function test_the_empty_state_is_shown_when_nothing_is_published(): void
    {
        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $this->published->update(['published_at' => null]);
        });

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->project))
            ->assertOk()
            ->assertSee(t('portal.no_diary'));
    }

    public function test_the_published_scope_excludes_drafts(): void
    {
        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $ids = DiaryEntry::published()->pluck('id')->all();

            $this->assertContains($this->published->id, $ids);
            $this->assertContains($this->foreignEntry->id, $ids);
            $this->assertNotContains($this->draft->id, $ids);
        });
    }

    /**
     * Fotolar `public` diskindədir, yəni birbaşa link sessiya tələb etmir.
     * Portal onları yalnız avtorizasiyalı marşrutla verir — bu testlər həmin
     * sərhədi qoruyur.
     */
    public function test_a_diary_photo_of_another_clients_project_is_not_served(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->otherProject->id, $this->foreignEntry->id, 0]))
            ->assertNotFound();
    }

    public function test_a_draft_entrys_photo_is_not_served(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project->id, $this->draft->id, 0]))
            ->assertNotFound();
    }

    /** Massivdən kənar indeks başqa qeydin faylına keçid verməməlidir. */
    public function test_an_out_of_range_photo_index_is_not_found(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project->id, $this->published->id, 99]))
            ->assertNotFound();
    }

    public function test_a_guest_cannot_fetch_a_diary_photo(): void
    {
        $this->get(route('portal.diary.photo', [$this->studio->project->id, $this->published->id, 0]))
            ->assertRedirect(route('portal.login'));
    }
}
