<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\DecisionSource;
use App\Models\Approval;
use App\Models\Comment;
use App\Models\Deliverable;
use App\Models\DiaryEntry;
use App\Models\ProjectDecision;
use App\Models\PunchListIssue;
use App\Services\Design\MaterialService;
use App\Support\TenantContext;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Portal — komanda ilə EYNİ altı bölmə (TZ «Layihə naviqasiyası» §1–2, §4–6,
 * qəbul 7, 12, 13, 14, 15, 17, ssenari 2).
 *
 * Yoxlanılır: naviqasiyanın sırası və yığcam mobil menyu, İcmalın «Sizdən
 * gözlənilən əməliyyatlar» bloku və təhlükəsiz hadisə lenti, mərhələdə yalnız
 * dərc edilmiş materialların sayı, «Fayllar» kartında qərar və müştəri şərhi,
 * yad müştərinin girişi.
 */
class PortalSectionsTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private MaterialService $materials;

    private Deliverable $material;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Notification::fake();
        $this->seed(TranslationSeeder::class);

        $this->studio = StudioWorld::make('sections');
        $this->materials = app(MaterialService::class);

        $this->material = $this->inTenant(function (): Deliverable {
            Storage::disk('public')->put('materials/plan-v1.pdf', '%PDF-1.4');

            return $this->materials->create($this->studio->project, [
                'title' => 'Mənzilin plan həlli',
                'type' => 'layout',
                'stage_id' => $this->studio->stage->id,
                'files' => ['materials/plan-v1.pdf'],
                'visibility' => MaterialService::VISIBILITY_PUBLISHED,
            ], $this->studio->user('designer'));
        });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);

        parent::tearDown();
    }

    // ── Naviqasiya ───────────────────────────────────────────────────────────

    public function test_every_project_page_shows_the_same_six_sections_in_order(): void
    {
        $portal = $this->actingAs($this->studio->portalUser, 'customer');

        foreach ([
            'portal.projects.show' => 'overview',
            'portal.stages' => 'stages',
            'portal.files' => 'files',
            'portal.procurement' => 'procurement',
            'portal.diary' => 'diary',
            // Bölmə olmayan səhifələr İcmaldan açılır — «İcmal» vurğulanır.
            'portal.payments' => 'overview',
            'portal.documents' => 'overview',
            'portal.approvals' => 'overview',
        ] as $route => $active) {
            $html = $portal->get(route($route, $this->studio->project))->assertOk()->getContent();

            preg_match_all('/data-section="([a-z]+)"/', $html, $matches);
            $this->assertSame(
                ['overview', 'brief', 'stages', 'files', 'procurement', 'diary'],
                $matches[1],
                $route.': bölmələr və ya sıra fərqlidir.'
            );

            $this->assertMatchesRegularExpression('/data-section="'.$active.'"\s+aria-current="page"/', $html, $route);

            // Dar ekran: uzun üfüqi sətir yox, yığcam menyu (qəbul 13); çat ayrıca keçiddir.
            $this->assertStringContainsString('data-section-nav="mobile"', $html);
            $this->assertStringContainsString('<select id="portal-section-select"', $html);
            $this->assertStringContainsString(route('portal.chat', $this->studio->project), $html);
        }

        $portal->get(route('portal.projects.show', $this->studio->project))
            ->assertSeeInOrder(['İcmal', 'Brif', 'Mərhələlər', 'Fayllar', 'Komplektasiya', 'Müəllif nəzarəti'])
            // Ödənişlər və müqavilələr bölmə deyil — İcmalda ikinci dərəcəli keçid.
            ->assertSee('Ödənişlər və müqavilələr')
            ->assertSee(route('portal.payments', $this->studio->project), false)
            ->assertSee(route('portal.documents', $this->studio->project), false);
    }

    public function test_section_labels_follow_the_interface_language(): void
    {
        $this->withSession(['locale' => 'en'])
            ->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.stages', $this->studio->project))
            ->assertOk()
            ->assertSeeInOrder(['Overview', 'Brief', 'Stages', 'Files', 'Procurement', 'Design supervision']);
    }

    // ── İcmal ────────────────────────────────────────────────────────────────

    public function test_the_overview_lists_a_pending_material_approval_with_a_direct_link(): void
    {
        $approval = $this->inTenant(fn () => $this->materials->sendForApproval(
            $this->material->fresh(), $this->material->currentVersion, $this->studio->user('project_manager'), now()->addDays(3),
        ));

        // Başqa müştərinin layihəsindəki razılaşdırma — siyahıya düşməməlidir.
        $this->inTenant(function (): void {
            Storage::disk('public')->put('materials/foreign.pdf', '%PDF-1.4');
            $foreign = $this->materials->create($this->studio->otherProject, [
                'title' => 'Yad layihənin planı',
                'type' => 'layout',
                'files' => ['materials/foreign.pdf'],
                'visibility' => MaterialService::VISIBILITY_PUBLISHED,
            ], $this->studio->user('owner'));
            $this->materials->sendForApproval($foreign, $foreign->currentVersion, $this->studio->user('owner'));
        });

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->studio->project))
            ->assertOk();

        $actions = $response->viewData('actions');
        $item = collect($actions)->firstWhere('key', 'approval-'.$approval->id);

        $this->assertNotNull($item, 'Material razılaşdırması «Sizdən gözlənilən»də yoxdur.');
        $this->assertSame(route('portal.files', $this->studio->project).'#material-'.$this->material->id, $item['url']);
        $this->assertSame(now()->addDays(3)->format('d.m.Y'), $item['due']);
        $this->assertStringContainsString('Mənzilin plan həlli (v1)', $item['title']);

        $response->assertSee('Sizdən gözlənilən əməliyyatlar')
            ->assertSee('#material-'.$this->material->id, false)
            ->assertDontSee('Yad layihənin planı');

        // İkinci müştəri öz layihəsində yalnız ÖZ razılaşdırmasını görür.
        $foreignActions = $this->actingAs($this->studio->secondPortalUser, 'customer')
            ->get(route('portal.projects.show', $this->studio->otherProject))
            ->assertOk()
            ->assertDontSee('Mənzilin plan həlli')
            ->viewData('actions');
        $this->assertCount(1, $foreignActions);
    }

    public function test_the_feed_shows_client_safe_events_only(): void
    {
        $this->inTenant(function (): void {
            $designer = $this->studio->user('designer');
            $version = $this->material->currentVersion;

            $this->materials->addComment($version, $designer, 'Daxili şərh: podratçıya demə', true);

            // Daha yeni DAXİLİ versiya — müştəriyə dərc edilmir.
            Storage::disk('public')->put('materials/plan-v2.pdf', '%PDF-1.4');
            $this->materials->addVersion($this->material->fresh(), ['materials/plan-v2.pdf'], [], $designer, 'işlənir');

            // Daxili material — heç dərc edilməyib.
            Storage::disk('public')->put('materials/gizli.pdf', '%PDF-1.4');
            $this->materials->create($this->studio->project, [
                'title' => 'Gizli eskiz', 'type' => 'drawing', 'files' => ['materials/gizli.pdf'],
            ], $designer);

            ProjectDecision::create([
                'project_id' => $this->studio->project->id,
                'title' => 'Daxili qərar: podratçı dəyişir',
                'source' => DecisionSource::Meeting,
                'decision' => 'Gizli',
                'made_by_user_id' => $this->studio->user('owner')->id,
                'decided_at' => now(),
            ]);

            PunchListIssue::create([
                'project_id' => $this->studio->project->id,
                'title' => 'İrad: kafel əyridir',
            ]);

            $this->studio->stage->update(['status' => 'review']);
        });

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->studio->project))
            ->assertOk();

        $texts = collect($response->viewData('feed'))->pluck('text')->implode(' | ');

        $this->assertStringContainsString('Material dərc edildi: Mənzilin plan həlli (v1)', $texts);
        $this->assertStringContainsString('Mərhələ «Eskiz» — Yoxlamada', $texts);

        $response->assertDontSee('Daxili şərh: podratçıya demə')
            ->assertDontSee('Daxili qərar: podratçı dəyişir')
            ->assertDontSee('İrad: kafel əyridir')
            ->assertDontSee('Gizli eskiz')
            ->assertDontSee('(v2)');
    }

    public function test_a_client_decision_appears_in_the_feed_with_its_version(): void
    {
        $approval = $this->inTenant(fn () => $this->materials->sendForApproval(
            $this->material->fresh(), $this->material->currentVersion, $this->studio->user('project_manager'),
        ));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'reject', 'comment' => 'Mətbəxi genişləndirin']);

        $feed = collect($this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->studio->project))
            ->viewData('feed'));

        $revision = $feed->firstWhere('type', 'revision');
        $this->assertNotNull($revision);
        $this->assertSame('Düzəliş tələb olundu: Mənzilin plan həlli (v1)', $revision['text']);
        $this->assertSame($this->studio->portalUser->name, $revision['actor']);
    }

    // ── Mərhələlər ───────────────────────────────────────────────────────────

    public function test_the_stage_counts_only_published_materials_and_opens_the_filtered_files(): void
    {
        $this->inTenant(function (): void {
            Storage::disk('public')->put('materials/x.pdf', '%PDF-1.4');
            // Eyni mərhələdə daxili material + mərhələsiz dərc edilmiş material.
            $this->materials->create($this->studio->project, [
                'title' => 'Daxili variant', 'type' => 'layout', 'stage_id' => $this->studio->stage->id, 'files' => ['materials/x.pdf'],
            ], $this->studio->user('designer'));
            $this->materials->create($this->studio->project, [
                'title' => 'Ümumi kollaj', 'type' => 'moodboard', 'files' => ['materials/x.pdf'],
                'visibility' => MaterialService::VISIBILITY_PUBLISHED,
            ], $this->studio->user('designer'));
        });

        $filesUrl = route('portal.files', [$this->studio->project, 'stage' => $this->studio->stage->id]);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.stages', $this->studio->project))
            ->assertOk()
            ->assertSee('Materiallar — 1')
            ->assertDontSee('Materiallar — 2')
            ->assertSee($filesUrl, false);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get($filesUrl)
            ->assertOk()
            ->assertSee('Mənzilin plan həlli')
            ->assertDontSee('Ümumi kollaj')
            ->assertDontSee('Daxili variant')
            // Aktiv süzgəc görünür və sıfırlanır.
            ->assertSee('Aktiv süzgəc')
            ->assertSee('Eskiz')
            ->assertSee('data-filter-reset', false);

        // Sıfırlanmış siyahıda ümumi material də var.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files', $this->studio->project))
            ->assertSee('Ümumi kollaj')
            ->assertDontSee('Aktiv süzgəc');
    }

    public function test_a_foreign_stage_id_in_the_filter_is_ignored(): void
    {
        $foreignStage = $this->inTenant(fn () => $this->studio->otherProject->stages()->create([
            'name' => 'Yad mərhələ', 'position' => 1, 'weight' => 1, 'status' => 'in_progress',
        ]));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files', [$this->studio->project, 'stage' => $foreignStage->id]))
            ->assertOk()
            ->assertSee('Mənzilin plan həlli')
            ->assertDontSee('Yad mərhələ')
            ->assertDontSee('Aktiv süzgəc');
    }

    // ── Fayllar ──────────────────────────────────────────────────────────────

    public function test_the_client_approves_the_version_right_on_the_material_card(): void
    {
        $approval = $this->inTenant(fn () => $this->materials->sendForApproval(
            $this->material->fresh(), $this->material->currentVersion, $this->studio->user('project_manager'),
        ));

        $filesUrl = route('portal.files', $this->studio->project);
        $portal = $this->actingAs($this->studio->portalUser, 'customer');

        $portal->get($filesUrl)
            ->assertOk()
            ->assertSee('id="material-'.$this->material->id.'"', false)
            ->assertSee(route('portal.approvals.decide', $approval), false)
            ->assertSee('Təsdiqlə')
            ->assertSee('Düzəliş tələb et');

        $portal->from($filesUrl)
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertRedirect($filesUrl);

        $approval->refresh();
        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertSame($this->studio->portalUser->id, $approval->client_user_id);
        $this->assertSame($this->material->currentVersion->id, $approval->deliverable_version_id);

        // Tarixçədə versiya, iştirakçı, nəticə; düymələr artıq yoxdur.
        $portal->get($filesUrl)
            ->assertSee('data-material-history', false)
            ->assertSee($this->studio->portalUser->name)
            ->assertSee('Təsdiqlənib')
            ->assertDontSee(route('portal.approvals.decide', $approval), false);
    }

    public function test_requesting_changes_on_the_card_requires_a_comment(): void
    {
        $approval = $this->inTenant(fn () => $this->materials->sendForApproval(
            $this->material->fresh(), $this->material->currentVersion, $this->studio->user('project_manager'),
        ));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->from(route('portal.files', $this->studio->project))
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'reject', 'comment' => ''])
            ->assertSessionHasErrors('comment');

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_a_client_comment_is_saved_as_client_visible_and_internal_comments_stay_hidden(): void
    {
        $version = $this->material->currentVersion;

        $this->inTenant(fn () => $this->materials->addComment($version, $this->studio->user('designer'), 'Daxili: ölçünü yoxla', true));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.materials.comments.store', [$this->studio->project, $this->material->id]), [
                'body' => 'Qonaq otağını bir az böyüdək',
            ])
            ->assertRedirect(route('portal.files', $this->studio->project).'#material-'.$this->material->id);

        $comment = Comment::query()->where('body', 'Qonaq otağını bir az böyüdək')->firstOrFail();
        $this->assertFalse($comment->is_internal);
        $this->assertSame('client_user', $comment->author_type);
        $this->assertSame($this->studio->portalUser->id, (int) $comment->author_id);
        $this->assertSame($version->id, (int) $comment->commentable_id);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files', $this->studio->project))
            ->assertSee('Qonaq otağını bir az böyüdək')
            ->assertDontSee('Daxili: ölçünü yoxla');
    }

    public function test_a_comment_cannot_target_an_unpublished_material_or_another_clients_project(): void
    {
        $internal = $this->inTenant(function (): Deliverable {
            Storage::disk('public')->put('materials/i.pdf', '%PDF-1.4');

            return $this->materials->create($this->studio->project, [
                'title' => 'Daxili', 'type' => 'drawing', 'files' => ['materials/i.pdf'],
            ], $this->studio->user('designer'));
        });

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.materials.comments.store', [$this->studio->project, $internal->id]), ['body' => 'x'])
            ->assertNotFound();

        $this->actingAs($this->studio->secondPortalUser, 'customer')
            ->post(route('portal.materials.comments.store', [$this->studio->project, $this->material->id]), ['body' => 'x'])
            ->assertNotFound();

        $this->assertSame(0, Comment::query()->where('body', 'x')->count());
    }

    public function test_a_client_of_another_project_cannot_open_any_section(): void
    {
        foreach (['portal.projects.show', 'portal.stages', 'portal.files', 'portal.procurement', 'portal.diary'] as $route) {
            $this->actingAs($this->studio->secondPortalUser, 'customer')
                ->get(route($route, $this->studio->project))
                ->assertNotFound($route);
        }

        // Yad müştərinin qərar marşrutu da bağlıdır.
        $approval = Approval::query()->where('project_id', $this->studio->project->id)->firstOrFail();
        $this->actingAs($this->studio->secondPortalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertNotFound();
    }

    // ── Komplektasiya və Müəllif nəzarəti ────────────────────────────────────

    public function test_procurement_shows_client_safe_specification_fields_but_never_the_supplier_cost(): void
    {
        $item = $this->inTenant(function () {
            Storage::disk('public')->put('procurement/attachments/spec.pdf', '%PDF-1.4');

            return $this->studio->project->procurementItems()->create([
                'name' => 'Divar lampası', 'brand' => 'Flos', 'model' => 'IC W1',
                'characteristics' => 'Mis, 2700K', 'selection_status' => 'client_review',
                'qty' => 2, 'price' => 300, 'supplier_cost' => 187.65,
                'attachments' => ['procurement/attachments/spec.pdf'],
                'visible_to_client' => true,
            ]);
        });

        $portal = $this->actingAs($this->studio->portalUser, 'customer');

        $portal->get(route('portal.procurement', $this->studio->project))
            ->assertOk()
            ->assertSee('Flos')
            ->assertSee('IC W1')
            ->assertSee('Mis, 2700K')
            ->assertSee('Baxışınızdadır')
            ->assertSee(route('portal.procurement.attachment', [$this->studio->project->id, $item->id, 0]), false)
            ->assertDontSee('187.65');

        $portal->get(route('portal.procurement.attachment', [$this->studio->project->id, $item->id, 0]))->assertOk();
        $portal->get(route('portal.procurement.attachment', [$this->studio->project->id, $item->id, 5]))->assertNotFound();

        $csv = $portal->get(route('portal.procurement.export', $this->studio->project))->streamedContent();
        $this->assertStringContainsString('Flos', $csv);
        $this->assertStringNotContainsString('187.65', $csv);

        $this->actingAs($this->studio->secondPortalUser, 'customer')
            ->get(route('portal.procurement.attachment', [$this->studio->project->id, $item->id, 0]))
            ->assertNotFound();
    }

    public function test_supervision_shows_published_visits_and_reports_but_no_punch_issues(): void
    {
        $this->inTenant(function (): void {
            DiaryEntry::create([
                'project_id' => $this->studio->project->id, 'kind' => 'visit', 'visited_on' => '2026-10-10',
                'body' => 'Səfər: elektrik xətləri yoxlanıldı', 'published_at' => now(),
            ]);
            DiaryEntry::create([
                'project_id' => $this->studio->project->id, 'kind' => 'report',
                'body' => 'Aylıq hesabat', 'published_at' => now()->subDay(),
            ]);
            DiaryEntry::create([
                'project_id' => $this->studio->project->id, 'kind' => 'visit', 'body' => 'Qaralama səfər',
            ]);
            PunchListIssue::create(['project_id' => $this->studio->project->id, 'title' => 'İrad: qapı əyilib']);
        });

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->project))
            ->assertOk()
            ->assertSee('Səfər: elektrik xətləri yoxlanıldı')
            ->assertSee('Səfər tarixi: 10.10.2026')
            ->assertSee('Aylıq hesabat')
            ->assertSee('Hesabat')
            ->assertDontSee('Qaralama səfər')
            ->assertDontSee('İrad: qapı əyilib');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }
}
