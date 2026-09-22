<?php

namespace Tests\Feature;

use App\Enums\BriefStatus;
use App\Enums\DocumentType;
use App\Models\Brief;
use App\Models\BriefQuestion;
use App\Services\Brief\BriefService;
use App\Support\TenantContext;
use Database\Seeders\BriefQuestionBankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Roomix axını: brif → TEXNİKİ TAPŞIRIQ → razılaşdırma → imzalanma.
 *
 * Texniki tapşırıq brifin surəti deyil — ondan çıxarılan tapşırıqdır: yalnız
 * cavablanmış tələbləri daşıyır və hazırlandığı anda müştəriyə görünmür,
 * çünki dizayner hələ üzərində işləyir.
 */
class TechnicalSpecTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(BriefQuestionBankSeeder::class);
        $this->studio = StudioWorld::make('tz');
    }

    private function submittedBrief(): Brief
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, function () {
            $brief = app(BriefService::class)->forProject($this->studio->project);

            $brief->answers()->create([
                'brief_question_id' => BriefQuestion::where('key', 'object_address')->firstOrFail()->id,
                'brief_room_id' => null,
                'value' => 'Bakı, Nizami 1',
                'answered_at' => now(),
            ]);

            $brief->forceFill(['status' => BriefStatus::Submitted->value, 'submitted_at' => now()])->save();

            return $brief->fresh();
        });
    }

    public function test_it_creates_a_versioned_document_that_starts_hidden_from_the_client(): void
    {
        $brief = $this->submittedBrief();

        $document = app(TenantContext::class)->actingAs(
            $this->studio->tenant->id,
            fn () => app(BriefService::class)->buildTechnicalSpec($brief),
        );

        $this->assertSame(DocumentType::TechnicalSpec, $document->type);
        $this->assertStringContainsString('v1', $document->title);
        $this->assertFalse(
            $document->visible_to_client,
            'Razılaşdırmaya göndərilənə qədər müştəri texniki tapşırığı görməməlidir.',
        );
        $this->assertTrue(Storage::disk('public')->exists($document->file_path));
    }

    public function test_each_rebuild_raises_the_version(): void
    {
        $brief = $this->submittedBrief();

        [$first, $second] = app(TenantContext::class)->actingAs($this->studio->tenant->id, function () use ($brief) {
            $service = app(BriefService::class);

            return [$service->buildTechnicalSpec($brief), $service->buildTechnicalSpec($brief)];
        });

        $this->assertStringContainsString('v1', $first->title);
        $this->assertStringContainsString('v2', $second->title);
        $this->assertNotSame($first->file_path, $second->file_path, 'Hər versiya öz faylı olmalıdır.');
    }

    /** Fayl adı təxmin edilə bilməməlidir — sənəddə ünvan və telefon var. */
    public function test_the_file_name_is_not_guessable(): void
    {
        $brief = $this->submittedBrief();

        $document = app(TenantContext::class)->actingAs(
            $this->studio->tenant->id,
            fn () => app(BriefService::class)->buildTechnicalSpec($brief),
        );

        $name = basename($document->file_path, '.pdf');

        $this->assertMatchesRegularExpression('/^tz-\d+-v\d+-[A-Za-z0-9]{24}$/', $name);
    }

    /** Gizli sənəd portalın sənəd siyahısına düşməməlidir. */
    public function test_a_hidden_technical_spec_is_not_listed_in_the_portal(): void
    {
        $brief = $this->submittedBrief();

        $document = app(TenantContext::class)->actingAs(
            $this->studio->tenant->id,
            fn () => app(BriefService::class)->buildTechnicalSpec($brief),
        );

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents', $this->studio->project))
            ->assertOk()
            ->assertDontSee($document->title);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [$this->studio->project, $document]))
            ->assertNotFound();
    }
}
