<?php

namespace Tests\Feature;

use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Services\Design\MaterialService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Müştəri tərəfi (TZ «Layihə naviqasiyası» §5–6, §10, qəbul meyarları 7, 12, 15):
 * müştəri YALNIZ dərc edilmiş versiyanı və müştəriyə açıq şərhləri görür;
 * daha yeni daxili versiya və daxili şərh birbaşa link ilə də açılmır.
 */
class MaterialsPortalTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private Deliverable $material;

    private DeliverableVersion $published;

    private DeliverableVersion $internal;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Notification::fake();

        $this->studio = StudioWorld::make('matportal');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $service = app(MaterialService::class);
            $designer = $this->studio->user('designer');

            foreach (['materials/plan-v1.pdf', 'materials/plan-v2.pdf', 'materials/gizli.pdf'] as $path) {
                Storage::disk('public')->put($path, '%PDF-1.4');
            }

            $this->material = $service->create($this->studio->project, [
                'title' => 'Mənzilin plan həlli',
                'type' => 'layout',
                'files' => ['materials/plan-v1.pdf'],
                'file_names' => ['materials/plan-v1.pdf' => 'plan-dərc.pdf'],
                'visibility' => MaterialService::VISIBILITY_PUBLISHED,
            ], $designer);
            $this->published = $this->material->currentVersion;

            $service->addComment($this->published, $designer, 'Daxili qeyd: ölçüləri yoxla', true);
            $service->addComment($this->published, $designer, 'Salam, planı nəzərdən keçirin', false);

            $this->internal = $service->addVersion($this->material->fresh(), ['materials/plan-v2.pdf'], ['materials/plan-v2.pdf' => 'plan-daxili.pdf'], $designer, 'işlənir');

            $service->create($this->studio->project, [
                'title' => 'Daxili eskiz',
                'type' => 'drawing',
                'files' => ['materials/gizli.pdf'],
            ], $designer);
        });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);

        parent::tearDown();
    }

    public function test_the_files_page_shows_only_the_published_version_and_client_comments(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files', $this->studio->project));

        $response->assertOk()
            ->assertSee('Mənzilin plan həlli')
            ->assertSee('v1')
            ->assertSee(route('portal.materials.download', [$this->studio->project, $this->material->id, $this->published->id, 0]), false)
            ->assertDontSee(route('portal.materials.download', [$this->studio->project, $this->material->id, $this->internal->id, 0]), false)
            ->assertSee('Salam, planı nəzərdən keçirin')
            ->assertDontSee('Daxili qeyd: ölçüləri yoxla')
            ->assertDontSee('Daxili eskiz');
    }

    public function test_the_published_version_downloads_but_a_newer_internal_version_does_not(): void
    {
        $portal = $this->actingAs($this->studio->portalUser, 'customer');

        $portal->get(route('portal.materials.download', [$this->studio->project, $this->material->id, $this->published->id, 0]))
            ->assertOk();

        // Daxili v2-nin id-si URL-də yazılsa belə — 404.
        $portal->get(route('portal.materials.download', [$this->studio->project, $this->material->id, $this->internal->id, 0]))
            ->assertNotFound();

        // Yad müştərinin hesabı eyni linki aça bilmir.
        $this->actingAs($this->studio->secondPortalUser, 'customer')
            ->get(route('portal.materials.download', [$this->studio->project, $this->material->id, $this->published->id, 0]))
            ->assertNotFound();
    }

    public function test_unpublishing_hides_the_material_and_its_direct_link(): void
    {
        app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => app(MaterialService::class)->unpublish($this->material->fresh()));

        $portal = $this->actingAs($this->studio->portalUser, 'customer');

        $portal->get(route('portal.files', $this->studio->project))->assertDontSee('Mənzilin plan həlli');
        $portal->get(route('portal.materials.download', [$this->studio->project, $this->material->id, $this->published->id, 0]))
            ->assertNotFound();
    }

    public function test_the_version_sent_for_approval_is_linked_on_the_approvals_page_and_decided_there(): void
    {
        $approval = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => app(MaterialService::class)
            ->sendForApproval($this->material->fresh(), $this->internal, $this->studio->user('project_manager')));

        $link = route('portal.materials.download', [$this->studio->project->id, $this->material->id, $this->internal->id, 0]);

        $portal = $this->actingAs($this->studio->portalUser, 'customer');
        $portal->get(route('portal.approvals', $this->studio->project))->assertOk()->assertSee($link, false);
        $portal->get($link)->assertOk();

        $portal->post(route('portal.approvals.decide', $approval), ['decision' => 'reject', 'comment' => 'Mətbəxi dəyişin'])
            ->assertRedirect();

        $approval->refresh();
        $this->assertSame($this->internal->id, $approval->deliverable_version_id);
        $this->assertSame($this->studio->portalUser->id, $approval->client_user_id);
        $this->assertNotNull($approval->decided_at);
        $this->assertSame('revision_required', $this->internal->fresh()->status->value);
    }
}
