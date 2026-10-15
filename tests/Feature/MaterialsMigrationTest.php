<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\FileVisibility;
use App\Models\Deliverable;
use App\Models\Document;
use App\Models\ProjectFile;
use App\Services\Approvals\ApprovalService;
use App\Services\Design\DeliverableService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Köhnə «Fayllar» / «Sənədlər» → material kartları (TZ «Layihə naviqasiyası»
 * §9–10): idempotent, dağıdıcı deyil, birmənalı olmayan qeydlər köçürülmür,
 * köhnə linklər yeni yerə yönləndirilir, görünürlük qorunur.
 */
class MaterialsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Notification::fake();

        $this->studio = StudioWorld::make('matmigr');
        $this->migration = require database_path('migrations/2026_10_15_200100_migrate_files_and_documents_to_materials.php');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_files_and_non_accounting_documents_become_one_version_materials_idempotently(): void
    {
        $drawing = $this->inTenant(fn () => Document::create([
            'project_id' => $this->studio->project->id,
            'type' => DocumentType::Drawing->value,
            'title' => 'İşçi çertyojlar',
            'file_path' => 'documents/drawings.pdf',
            'size' => 2048,
            'mime' => 'application/pdf',
            'uploaded_by_type' => 'user',
            'uploaded_by_id' => $this->studio->user('designer')->id,
            'visible_to_client' => true,
        ]));

        $clientUpload = $this->inTenant(fn () => ProjectFile::create([
            'project_id' => $this->studio->project->id,
            'category' => 'image',
            'visibility' => FileVisibility::ClientUploaded->value,
            'title' => 'Obyektin fotosu',
            'file_path' => 'files/photo.jpg',
            'uploaded_by_type' => 'client_user',
            'uploaded_by_id' => $this->studio->portalUser->id,
        ]));

        $this->migration->up();
        $this->migration->up(); // idempotent

        $shared = $this->studio->sharedFile->fresh();
        $internal = $this->studio->internalFile->fresh();

        $this->assertNotNull($shared->migrated_deliverable_id);
        $this->assertNotNull($internal->migrated_deliverable_id);
        $this->assertNotNull($drawing->fresh()->migrated_deliverable_id);

        // 3 fayl + 1 çertyoj = 4 material, hər biri bir versiya; təkrar işə salma dublikat yaratmır.
        $this->assertSame(4, Deliverable::withoutGlobalScopes()->count());
        $this->assertSame(4, DB::table('deliverable_versions')->count());

        $material = Deliverable::withoutGlobalScopes()->with('currentVersion')->find($shared->migrated_deliverable_id);
        $this->assertSame('Paylaşılan cizgi', $material->title);
        $this->assertSame('layout', $material->type->value);
        $this->assertSame($this->studio->tenant->id, $material->tenant_id);
        $this->assertSame($material->current_version_id, $material->published_version_id, 'Müştəri ilə paylaşılmış fayl dərc edilmiş qalmalıdır.');
        $this->assertSame($shared->file_path, $material->currentVersion->file_path, 'Fayl surəti yaradılmır — eyni yol.');
        $this->assertNull($material->stage_id, 'Mərhələ uydurulmur — ümumi material.');

        $this->assertNull(Deliverable::withoutGlobalScopes()->find($internal->migrated_deliverable_id)->published_version_id, 'Daxili fayl müştəriyə açılmamalıdır.');

        $photo = Deliverable::withoutGlobalScopes()->with('currentVersion')->find($clientUpload->fresh()->migrated_deliverable_id);
        $this->assertTrue($photo->currentVersion->isClientUploaded(), 'Müştərinin yüklədiyi fayl belə işarələnməlidir.');
        $this->assertSame('photo', $photo->type->value);

        $drawingMaterial = Deliverable::withoutGlobalScopes()->with('currentVersion')->find($drawing->fresh()->migrated_deliverable_id);
        $this->assertSame('drawing', $drawingMaterial->type->value);
        $this->assertSame($this->studio->user('designer')->id, $drawingMaterial->currentVersion->created_by_user_id);
        $this->assertSame(2048, $drawingMaterial->currentVersion->files()[0]['size']);

        // Mühasibat və birmənalı olmayan sənədlər köçürülmür; köhnə sətirlər silinmir.
        $this->assertNull($this->studio->clientDocument->fresh()->migrated_deliverable_id, 'Müqavilə mühasibatlıqda qalır.');
        $this->assertNull($this->studio->internalDocument->fresh()->migrated_deliverable_id, '«Digər» tipli sənəd avtomatik bölüşdürülmür.');
        $this->assertSame(3, ProjectFile::withoutGlobalScopes()->count());
        $this->assertSame(3, Document::withoutGlobalScopes()->count());

        $this->artisan('materials:legacy-report')
            ->expectsOutputToContain('YOXLANMALI')
            ->assertSuccessful();
    }

    public function test_old_download_links_redirect_to_the_material_through_authorizing_routes(): void
    {
        $drawing = $this->inTenant(fn () => Document::create([
            'project_id' => $this->studio->project->id,
            'type' => DocumentType::Drawing->value,
            'title' => 'Çertyoj',
            'file_path' => 'documents/cert.pdf',
            'visible_to_client' => true,
        ]));

        foreach ([$this->studio->sharedFile->file_path, $this->studio->internalFile->file_path, 'documents/cert.pdf'] as $path) {
            Storage::disk('public')->put($path, 'x');
        }

        $this->migration->up();

        $shared = $this->studio->sharedFile->fresh();
        $sharedMaterial = Deliverable::withoutGlobalScopes()->find($shared->migrated_deliverable_id);
        $internalMaterial = Deliverable::withoutGlobalScopes()->find($this->studio->internalFile->fresh()->migrated_deliverable_id);

        // Panel: köhnə `files.download` → `materials.download` (policy yenidən yoxlanır).
        $owner = $this->studio->user('owner');
        AccessMatrix::flushCache();
        $this->actingAs($owner)
            ->get(route('files.download', $shared))
            ->assertRedirect(route('materials.download', ['version' => $sharedMaterial->current_version_id, 'index' => 0]));
        $this->actingAs($owner)->get(route('materials.download', ['version' => $sharedMaterial->current_version_id, 'index' => 0]))->assertOk();

        // Portal: dərc edilmiş materiala yönləndirilir.
        $portal = $this->actingAs($this->studio->portalUser, 'customer');
        $portal->get(route('portal.files.download', [$this->studio->project, $shared]))
            ->assertRedirect(route('portal.materials.download', [$this->studio->project->id, $sharedMaterial->id, $sharedMaterial->published_version_id, 0]));

        $drawingMaterial = Deliverable::withoutGlobalScopes()->find($drawing->fresh()->migrated_deliverable_id);
        $portal->get(route('portal.documents.download', [$this->studio->project, $drawing]))
            ->assertRedirect(route('portal.materials.download', [$this->studio->project->id, $drawingMaterial->id, $drawingMaterial->published_version_id, 0]));

        // Daxili faylın köhnə linki müştəriyə heç vaxt açılmır.
        $portal->get(route('portal.files.download', [$this->studio->project, $this->studio->internalFile]))->assertNotFound();
        $this->assertNull($internalMaterial->published_version_id);

        // Material dərcdən çıxarılanda köhnə link də bağlanır və sənəd siyahıda görünmür.
        DB::table('deliverables')->where('id', $drawingMaterial->id)->update(['published_version_id' => null, 'client_visible' => false]);
        $portal->get(route('portal.documents.download', [$this->studio->project, $drawing]))->assertNotFound();
        $portal->get(route('portal.documents', $this->studio->project))->assertOk()->assertDontSee('Çertyoj');
    }

    public function test_existing_deliverable_approvals_are_bound_to_the_version_that_was_sent(): void
    {
        [$approval, $deliverable, $v1] = $this->inTenant(function () {
            $deliverable = $this->studio->project->deliverables()->create(['title' => 'Köhnə konsept', 'type' => 'concept']);
            $v1 = app(DeliverableService::class)->createVersion($deliverable, 'deliverables/c1.pdf', $this->studio->user('designer'));
            $approval = app(ApprovalService::class)->request($deliverable->fresh(), $this->studio->user('project_manager'));

            return [$approval, $deliverable, $v1];
        });

        // Köhnə vəziyyəti təqlid et: versiya bağlantısı və dərc yoxdur, yalnız client_visible.
        DB::table('approvals')->where('id', $approval->id)->update(['deliverable_version_id' => null, 'created_at' => now()->addMinute()]);
        DB::table('deliverables')->where('id', $deliverable->id)->update(['client_visible' => true, 'published_version_id' => null]);

        // Sonradan daxili v2 yaradılıb — müştəriyə açılmamalıdır.
        $this->travel(5)->minutes();
        $v2 = $this->inTenant(fn () => app(DeliverableService::class)->createVersion($deliverable->fresh(), 'deliverables/c2.pdf', $this->studio->user('designer')));

        $this->migration->up();

        $this->assertSame($v1->id, (int) DB::table('approvals')->where('id', $approval->id)->value('deliverable_version_id'));
        $this->assertSame($v1->id, (int) DB::table('deliverables')->where('id', $deliverable->id)->value('published_version_id'));
        $this->assertNotSame($v2->id, (int) DB::table('deliverables')->where('id', $deliverable->id)->value('published_version_id'));
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }
}
