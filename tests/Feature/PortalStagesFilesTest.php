<?php

namespace Tests\Feature;

use App\Enums\FileVisibility;
use App\Models\ProjectFile;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Roomix-dəki «Stages» və «Files» tabları.
 *
 * Hər iki səhifə layihənin bütün məzmununu bir yerə yığır — yəni səhv
 * scope-lama dərhal yad müştərinin fayllarını göstərər. Testlər məhz o
 * sərhədi qoruyur: eyni studiyanın BAŞQA müştərisi ilə yoxlanılır.
 */
class PortalStagesFilesTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private ProjectFile $foreignFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('stages-files');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $this->studio->project->files()->create([
                'category' => 'image',
                'visibility' => FileVisibility::ClientShared->value,
                'title' => 'Mətbəx renderi',
                'file_path' => 'files/kitchen.jpg',
            ]);

            $this->foreignFile = ProjectFile::create([
                'project_id' => $this->studio->otherProject->id,
                'category' => 'plan',
                'visibility' => FileVisibility::ClientShared->value,
                'title' => 'Yad plan',
                'file_path' => 'files/foreign.pdf',
            ]);
        });
    }

    public function test_the_stages_tab_lists_the_projects_stages(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.stages', $this->studio->project));

        $response->assertOk();
        $response->assertSee($this->studio->stage->name);
        $response->assertSee($this->studio->stage->status->label());
    }

    public function test_a_customer_cannot_open_the_stages_of_another_clients_project(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.stages', $this->studio->otherProject))
            ->assertNotFound();
    }

    public function test_the_files_tab_shows_shared_files_and_hides_internal_ones(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files', $this->studio->project));

        $response->assertOk();
        $response->assertSee('Mətbəx renderi');
        $response->assertSee($this->studio->sharedFile->title);

        $response->assertDontSee($this->studio->internalFile->title);
        $response->assertDontSee('Yad plan');
    }

    /** Filtr çipi yalnız öz kateqoriyasını buraxmalıdır. */
    public function test_the_media_filter_narrows_the_list_to_images(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files', [$this->studio->project, 'filter' => 'media']));

        $response->assertOk();
        $response->assertSee('Mətbəx renderi');
        // «Paylaşılan cizgi» plan kateqoriyasındadır — media filtrində olmamalıdır.
        $response->assertDontSee($this->studio->sharedFile->title);
    }

    /** Uydurma filtr dəyəri səhifəni sındırmamalı, hamısını göstərməlidir. */
    public function test_an_unknown_filter_falls_back_to_all_files(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files', [$this->studio->project, 'filter' => 'drop-table']));

        $response->assertOk();
        $this->assertSame('all', $response->viewData('filter'));
        $response->assertSee('Mətbəx renderi');
    }

    public function test_a_customer_cannot_download_another_clients_file(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files.download', [$this->studio->project, $this->foreignFile]))
            ->assertNotFound();
    }

    /** Daxili fayl paylaşılmayıb — birbaşa id ilə də çəkilməməlidir. */
    public function test_an_internal_file_cannot_be_downloaded_by_the_customer(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files.download', [$this->studio->project, $this->studio->internalFile]))
            ->assertNotFound();
    }
}
