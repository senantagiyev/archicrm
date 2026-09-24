<?php

namespace Tests\Feature\QA2;

use App\Enums\ApprovalStatus;
use App\Enums\DeliverableStatus;
use App\Enums\DeliverableType;
use App\Enums\DeliverableVersionStatus;
use App\Enums\FileVisibility;
use App\Enums\ProjectStatus;
use App\Enums\PunchIssuePriority;
use App\Enums\PunchIssueStatus;
use App\Filament\Pages\ChatCenter;
use App\Filament\Resources\ApprovalResource;
use App\Filament\Resources\MeetingResource;
use App\Filament\Resources\MeetingResource\Pages\CreateMeeting;
use App\Filament\Resources\MeetingResource\Pages\EditMeeting;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\DeliverablesRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\DiaryRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\FilesRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\ProcurementItemsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\PunchListRelationManager;
use App\Models\Approval;
use App\Models\AutomationRule;
use App\Models\ChatMessage;
use App\Models\Deliverable;
use App\Models\Document;
use App\Models\Meeting;
use App\Models\ProcurementItem;
use App\Models\ProjectFile;
use App\Models\PunchListIssue;
use App\Models\Task;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Design\DeliverableService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA2 — Sənədlər, fayllar, gündəlik, razılaşdırmalar, görüşlər və çat.
 *
 * Niyə bu testlər bu formadadır: layihənin ən ağrılı reqressiyası «yükləyən
 * disk ≠ oxuyan disk» idi — paneldən yüklənən HƏR fayl `storage/app/private`-a
 * düşürdü, oxuyanlar isə `public` diskinə baxırdı, nəticədə müştəri portalında
 * hər endirmə 404/500 verirdi. `UploadDiskTest` yalnız sahənin ELANINI qoruyur
 * (kodda `->disk('public')` var-yoxdur). Burada isə TAM DÖVRƏ sürülür: fayl
 * real Filament relation manager-i vasitəsilə yüklənir, sonra real portal
 * marşrutundan müştəri kimi endirilir və BAYTLARI müqayisə olunur. Deklarasiya
 * doğru olub, dövrə qırıq qala bilər — məhz bu baş vermişdi.
 */
class DocsApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    /** Bu prosesə məxsus `public` disk kökü. */
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // NİYƏ `Storage::fake()` DEYİL: `Storage::fake('public')` kökü həmişə
        // `storage/framework/testing/disks/public`-dir və çağırılanda həmin
        // qovluğu BÜTÜNLÜKLƏ silir. Repo üzərində paralel test prosesləri
        // işlədikdə (QA dəstləri belə işləyir) başqa prosesin `fake()` çağırışı
        // bu testin yüklədiyi faylı ortadan silir və endirmə təsadüfi 404 verir.
        // Fayl dövrəsini sürən test məhz bu səbəbdən yalançı qırmızı olmamalıdır,
        // ona görə disk prosesə məxsus kökdə qurulur.
        $this->diskRoot = storage_path('framework/testing/disks/qa2-docs-'.getmypid());

        File::deleteDirectory($this->diskRoot);
        File::ensureDirectoryExists($this->diskRoot);

        config(['filesystems.disks.public' => [
            'driver' => 'local',
            'root' => $this->diskRoot,
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
        ]]);

        Storage::forgetDisk('public');

        $this->studio = StudioWorld::make('qa2docs');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);

        parent::tearDown();
    }

    // ───────────────────────────── köməkçilər ─────────────────────────────

    /** Filament panelini konkret işçi kimi aç (tenant + matris keşi ilə). */
    private function asStaff(string $role): User
    {
        $user = $this->studio->user($role);

        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->studio->tenant->id);
        AccessMatrix::flushCache();

        return $user;
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }

    /**
     * Relation manager-i layihənin redaktə səhifəsinin içindəki kimi qur.
     *
     * `ownerRecord` + `pageClass` olmadan Filament RM-i mount edə bilmir.
     */
    private function relationManager(string $class, $owner = null)
    {
        return Livewire::test($class, [
            'ownerRecord' => $owner ?? $this->studio->project,
            'pageClass' => EditProject::class,
        ]);
    }

    // ═══════════════ 1. YÜKLƏ → SİYAHI → ENDİR TAM DÖVRƏSİ ═══════════════

    /**
     * BLOKER QORUYUCUSU — sənəd: panel → disk → portal endirmə.
     *
     * Yalnız `->disk('public')` elanını yoxlamaq kifayət deyildi: fayl doğru
     * diskə düşsə də portal marşrutu onu tapmaya bilər (yol, ad, `Content-Type`).
     * Ona görə burada endirilən cavabın BAYTLARI yüklənənlə tutuşdurulur.
     */
    public function test_a_document_uploaded_from_the_panel_downloads_through_the_portal(): void
    {
        $this->asStaff('project_manager');

        $bytes = 'QA2 müqavilə məzmunu — bayt-bayt yoxlanılır.';
        $upload = UploadedFile::fake()->createWithContent('muqavile.pdf', $bytes);

        $this->relationManager(DocumentsRelationManager::class)
            ->mountTableAction('create')
            ->setTableActionData([
                'title' => 'QA2 müqaviləsi',
                'type' => 'contract',
                'file_path' => $upload,
                'visible_to_client' => true,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $document = $this->inTenant(fn () => Document::where('title', 'QA2 müqaviləsi')->first());

        $this->assertNotNull($document, 'Sənəd yaradılmadı — forma saxlanmayıb.');
        $this->assertTrue(
            Storage::disk('public')->exists($document->file_path),
            'Fayl `public` diskində yoxdur: '.$document->file_path.' — yükləyən və oxuyan disk yenə uyğunsuzdur.',
        );

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [$this->studio->project->id, $document->id]));

        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent(), 'Endirilən baytlar yüklənənlə üst-üstə düşmür.');
        $this->assertStringContainsString(
            'QA2',
            (string) $response->headers->get('content-disposition'),
            'Fayl adı anlaşılan deyil — müştəri təsadüfi hash alır.',
        );
    }

    /**
     * Layihə faylı: panel → «Müştəriyə dərc et» → portal endirmə.
     *
     * Formada `visibility` sahəsi yoxdur (defolt `internal`), yəni dövrə yalnız
     * dərc action-ı ilə tamamlanır — o da testin bir hissəsidir.
     */
    public function test_a_project_file_uploaded_from_the_panel_downloads_through_the_portal_after_publishing(): void
    {
        $this->asStaff('project_manager');

        $bytes = 'QA2 plan faylının məzmunu.';
        $upload = UploadedFile::fake()->createWithContent('plan.pdf', $bytes);

        $this->relationManager(FilesRelationManager::class)
            ->mountTableAction('create')
            ->setTableActionData([
                'title' => 'QA2 planı',
                'category' => 'plan',
                'file_path' => $upload,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $file = $this->inTenant(fn () => ProjectFile::where('title', 'QA2 planı')->first());

        $this->assertNotNull($file, 'Fayl yaradılmadı.');
        $this->assertTrue(
            Storage::disk('public')->exists($file->file_path),
            'Fayl `public` diskində yoxdur: '.$file->file_path,
        );

        // Daxili fayl portalda görünmür — dövrənin ilk yarısı budur.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files.download', [$this->studio->project->id, $file->id]))
            ->assertNotFound();

        $this->asStaff('project_manager');
        $this->relationManager(FilesRelationManager::class)
            ->callTableAction('publishToClient', $file->getKey())
            ->assertHasNoTableActionErrors();

        $this->assertSame(
            FileVisibility::ClientShared,
            $this->inTenant(fn () => $file->fresh()->visibility),
            '«Müştəriyə dərc et» action-ı görünürlüyü dəyişmədi.',
        );

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files.download', [$this->studio->project->id, $file->id]));

        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent(), 'Dərc olunmuş faylın baytları uyğun gəlmir.');
    }

    /**
     * Gündəlik fotosu: panel → dərc → portalda ŞƏKİL kimi açılır.
     *
     * Portal fotoyu `inline` və `nosniff` ilə verir; tipi də yoxlayırıq, çünki
     * `application/octet-stream` qaytarılsa müştəri şəkil yerinə endirmə alır.
     */
    public function test_a_diary_photo_uploaded_from_the_panel_is_served_to_the_client(): void
    {
        $this->asStaff('designer');

        $this->relationManager(DiaryRelationManager::class)
            ->mountTableAction('create')
            ->setTableActionData([
                'body' => 'Mətbəxdə kafel işi tamamlandı.',
                'photos' => [UploadedFile::fake()->image('kafel.jpg', 40, 40)],
                'published_at' => now()->subHour()->format('Y-m-d H:i:s'),
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $entry = $this->inTenant(fn () => $this->studio->project->diaryEntries()->latest('id')->first());

        $this->assertNotNull($entry, 'Gündəlik qeydi yaradılmadı.');
        $this->assertNotEmpty($entry->photos, 'Foto massivi boşdur — yükləmə qeydə düşməyib.');
        $this->assertTrue(
            Storage::disk('public')->exists($entry->photos[0]),
            'Foto `public` diskində yoxdur: '.$entry->photos[0],
        );

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project->id, $entry->id, 0]));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('content-type'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));

        // Portal səhifəsinin özü də şəkli avtorizasiyalı marşrutla göstərməlidir.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->project->id))
            ->assertOk()
            ->assertSee(route('portal.diary.photo', [$this->studio->project->id, $entry->id, 0]), false);
    }

    /**
     * Komplektasiya fotosu: panel → portalda şəkil.
     *
     * `procurement_items.visible_to_client` defolt `false`-dur, yəni pozisiya
     * portalda görünmək üçün paneldən AÇILMALIDIR. Bu test həmin açarın
     * ümumiyyətlə mövcud olduğunu da sürür.
     */
    public function test_a_procurement_photo_uploaded_from_the_panel_is_served_to_the_client(): void
    {
        $this->asStaff('procurement');

        $this->relationManager(ProcurementItemsRelationManager::class)
            ->mountTableAction('create')
            ->setTableActionData([
                'name' => 'QA2 divan',
                'price' => 1500,
                'qty' => 1,
                'photo_path' => UploadedFile::fake()->image('divan.jpg', 30, 30),
                'visible_to_client' => true,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $item = $this->inTenant(fn () => ProcurementItem::where('name', 'QA2 divan')->first());

        $this->assertNotNull($item, 'Komplektasiya pozisiyası yaradılmadı.');
        $this->assertNotNull($item->photo_path, 'Foto yolu yazılmadı.');
        $this->assertTrue(
            Storage::disk('public')->exists($item->photo_path),
            'Foto `public` diskində yoxdur: '.$item->photo_path,
        );

        // ƏSAS TƏLƏB: paneldən yaradılan pozisiya müştəriyə AÇILA bilməlidir.
        // Formada `visible_to_client` açarı yoxdursa dəyər `false` qalır və
        // portalın bütün Komplektasiya ekranı ölü olur.
        $this->assertTrue(
            (bool) $item->visible_to_client,
            'Paneldən pozisiyanı müştəriyə açmaq mümkün deyil — formada `visible_to_client` sahəsi yoxdur, '
            .'ona görə portalın Komplektasiya siyahısı, CSV ixracı və foto marşrutu heç vaxt məzmun almır.',
        );

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.photo', [$this->studio->project->id, $item->id]));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('content-type'));
    }

    /**
     * Komplektasiyanın Excel ixracı BÜTÜN alış qiymətlərini daşıyır, ona görə
     * Komplektasiya domeni olmayan rol onu görməməlidir.
     *
     * Qeyd: `visible()` closure-ındaki `ProcurementItem::class` uzun müddət
     * İMPORT EDİLMƏMİŞ qalmışdı və relation manager-in öz namespace-inə həll
     * olunurdu. Yoxlama yalnız təsadüfən işləyirdi: Laravel-in policy ad
     * təxminçisi namespace-i seqment-seqment yuxarı gəzir və sinfin BAŞ ADINA
     * görə `App\Policies\ProcurementItemPolicy`-ni tapır. Bu test həmin
     * təsadüfdən asılı olmadan faktiki nəticəni — hüquqsuz rolun düyməni
     * görmədiyini — qoruyur.
     */
    public function test_the_procurement_excel_export_is_hidden_from_a_role_without_the_procurement_domain(): void
    {
        // Vizualizatorun matrisində Komplektasiya = Yoxdur (AccessMatrix::LEVELS).
        $this->asStaff('visualizer');

        // Header action-ın `visible()` closure-ı birbaşa Filament obyektindən
        // qiymətləndirilir: `assertTableActionVisible` sətir action-ları üçündür.
        $action = collect($this->relationManager(ProcurementItemsRelationManager::class)
            ->instance()
            ->getTable()
            ->getHeaderActions())
            ->first(fn ($candidate) => $candidate->getName() === 'exportExcel');

        $this->assertNotNull($action, 'Excel ixracı action-ı cədvəldə yoxdur.');
        $this->assertFalse(
            $action->isVisible(),
            'Komplektasiya hüququ olmayan rol Excel ixracı düyməsini görür — `visible()` yoxlaması '
            .'import edilməmiş sinfə görə fail-open işləyir.',
        );

        // Hüququ OLAN rol isə düyməni görməlidir — düzəliş yoxlamanı tərsinə çevirməsin.
        $this->asStaff('procurement');

        $entitled = collect($this->relationManager(ProcurementItemsRelationManager::class)
            ->instance()
            ->getTable()
            ->getHeaderActions())
            ->first(fn ($candidate) => $candidate->getName() === 'exportExcel');

        $this->assertTrue($entitled->isVisible(), 'Komplektasiya rolu öz modulunun ixracını görmür.');
    }

    // ═══════════ 2. FAYLI İTMİŞ SƏTİR — TƏMİZ 404, HEÇ VAXT 500 ═══════════

    /**
     * BLOKER — sahibin özü `/portal/projects/4/documents/2/download` ünvanında
     * 500 almışdı.
     *
     * Səbəb: `DocumentController::download()` `Storage::disk('public')->download()`
     * çağırışını heç bir mövcudluq yoxlaması olmadan edirdi. Disk faylı tapmasa
     * Flysystem istisna atır və müştəri sındırılmış səhifə görür. Fayl itə bilər
     * (əl ilə silinmə, köçürmə, natamam bərpa) — bu, 500 üçün əsas deyil.
     *
     * Müqayisə üçün `FileController::download()` bu yoxlamanı ONSUZ DA edirdi,
     * yəni fərq təsadüfi idi, qəsdən deyil.
     */
    public function test_a_document_row_whose_file_is_missing_gives_a_clean_404_not_a_500(): void
    {
        // Sənəd `StudioWorld`-də yaradılır, faylı isə heç vaxt diskə yazılmır —
        // məhz istehsalatdaki vəziyyət.
        $this->assertFalse(
            Storage::disk('public')->exists($this->studio->clientDocument->file_path),
            'Ssenari üçün fayl diskdə OLMAMALIDIR.',
        );

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [
                $this->studio->project->id,
                $this->studio->clientDocument->id,
            ]))
            ->assertNotFound();
    }

    /**
     * Fayl yolu BOŞ SƏTİR olan sənəd də 500 verməməlidir.
     *
     * `documents.file_path` sütunu `NOT NULL`-dur (yəni `null` hal DB
     * səviyyəsində mümkün deyil), amma boş sətir keçir — köçürmə/import zamanı
     * real vəziyyətdir. `pathinfo('')` boş qaytarır, `Storage::download('')` isə
     * yenidən istisna atardı.
     */
    public function test_a_document_row_with_a_blank_file_path_gives_a_clean_404(): void
    {
        $document = $this->inTenant(fn () => Document::create([
            'project_id' => $this->studio->project->id,
            'type' => 'other',
            'title' => 'Yolu olmayan sənəd',
            'file_path' => '',
            'visible_to_client' => true,
        ]));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [$this->studio->project->id, $document->id]))
            ->assertNotFound();
    }

    /** Layihə faylı üçün eyni qayda — bu yolda yoxlama əvvəldən var idi. */
    public function test_a_project_file_row_whose_file_is_missing_gives_a_clean_404(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files.download', [
                $this->studio->project->id,
                $this->studio->sharedFile->id,
            ]))
            ->assertNotFound();
    }

    /** Gündəlik fotosu: massivdə yol var, diskdə fayl yoxdur → 404. */
    public function test_a_diary_photo_whose_file_is_missing_gives_a_clean_404(): void
    {
        $entry = $this->inTenant(fn () => $this->studio->project->diaryEntries()->create([
            'author_user_id' => $this->studio->user('designer')->id,
            'body' => 'Fotosu itmiş qeyd.',
            'photos' => ['diary-photos/itmis.jpg'],
            'published_at' => now()->subHour(),
        ]));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project->id, $entry->id, 0]))
            ->assertNotFound();

        // Massivdən kənar indeks də 404-dür (başqa qeydin faylına keçid olmaz).
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project->id, $entry->id, 7]))
            ->assertNotFound();
    }

    /** Komplektasiya fotosu: sətir var, fayl yoxdur → 404. */
    public function test_a_procurement_photo_whose_file_is_missing_gives_a_clean_404(): void
    {
        $item = $this->inTenant(function () {
            $this->studio->procurementItem->update([
                'photo_path' => 'procurement/itmis.jpg',
                'visible_to_client' => true,
            ]);

            return $this->studio->procurementItem;
        });

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.photo', [$this->studio->project->id, $item->id]))
            ->assertNotFound();
    }

    // ═════════════════ 3. ENDİRMƏ AVTORİZASİYASI (IDOR) ═════════════════

    /**
     * Müştəri A eyni studiyanın BAŞQA müştərisinin sənədini endirə bilməz.
     *
     * Kirayəçi (tenant) filtri bunu TUTMUR — iki layihə eyni studiyadadır.
     * Sərhəd yalnız `ResolvesClientProjects` trait-indəki `client->projects()`
     * sorğusudur, ona görə hər fayl marşrutu üçün ayrıca sürülür.
     */
    public function test_a_client_cannot_download_documents_of_another_clients_project(): void
    {
        $foreignDocument = $this->inTenant(fn () => Document::create([
            'project_id' => $this->studio->otherProject->id,
            'type' => 'contract',
            'title' => 'Yad müqavilə',
            'file_path' => 'documents/yad.pdf',
            'visible_to_client' => true,
        ]));

        Storage::disk('public')->put('documents/yad.pdf', 'yad məzmun');

        // Öz marşrutuna yad id yazmaq (IDOR) da, yad layihənin marşrutu da bağlı.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [$this->studio->project->id, $foreignDocument->id]))
            ->assertNotFound();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [$this->studio->otherProject->id, $foreignDocument->id]))
            ->assertNotFound();

        // Öz layihəsinin DAXİLİ sənədi də verilmir.
        Storage::disk('public')->put($this->studio->internalDocument->file_path, 'daxili');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [
                $this->studio->project->id,
                $this->studio->internalDocument->id,
            ]))
            ->assertNotFound();
    }

    /** BAŞQA STUDİYANIN sənədi — kirayəçi sərhədi. */
    public function test_a_client_cannot_download_a_document_of_another_studio(): void
    {
        $other = StudioWorld::make('qa2other');

        app(TenantContext::class)->actingAs($other->tenant->id, function () use ($other): void {
            $other->clientDocument->update(['file_path' => 'documents/digger-studiya.pdf']);
        });

        Storage::disk('public')->put('documents/digger-studiya.pdf', 'digər studiyanın sənədi');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [$other->project->id, $other->clientDocument->id]))
            ->assertNotFound();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.files.download', [$other->project->id, $other->sharedFile->id]))
            ->assertNotFound();
    }

    /**
     * SİLİNMİŞ (soft-delete) layihənin sənədi verilmir.
     *
     * `Project` `SoftDeletes` işlədir; `client->projects()` sorğusu silinmişləri
     * onsuz da süzür, amma bu sərhəd açıq test tələb edir — silinmiş layihənin
     * faylları diskdə qalır və linki bilən müştəri onları oxumağa davam edərdi.
     */
    public function test_a_document_of_a_soft_deleted_project_is_not_downloadable(): void
    {
        Storage::disk('public')->put($this->studio->clientDocument->file_path, 'silinmiş layihənin sənədi');

        // Əvvəlcə işlədiyini təsdiqləyirik ki, sonrakı 404 məhz silinməyə görə olsun.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [
                $this->studio->project->id,
                $this->studio->clientDocument->id,
            ]))
            ->assertOk();

        $this->inTenant(fn () => $this->studio->project->delete());

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [
                $this->studio->project->id,
                $this->studio->clientDocument->id,
            ]))
            ->assertNotFound();
    }

    /**
     * Arxivlənmiş (soft-delete olunmuş) MÜŞTƏRİ — portal hesabı hələ mövcuddur.
     *
     * `client_users` soft-delete olunmur, ona görə hesab autentifikasiyadan keçir
     * və `$viewer->client` `null` olur. Trait bunu 403 ilə bağlayır (əvvəl hər
     * portal səhifəsi, fon pollinqi də daxil, 500 verirdi).
     */
    public function test_a_document_is_not_downloadable_once_the_client_is_archived(): void
    {
        Storage::disk('public')->put($this->studio->clientDocument->file_path, 'məzmun');

        // Müştərini silmək üçün layihəsi əvvəlcə arxivlənməlidir (Client::deleting
        // guard-ı sahibsiz aktiv layihə qalmasına yol vermir).
        $this->inTenant(function (): void {
            $this->studio->project->update(['status' => ProjectStatus::Archived->value]);
            $this->studio->client->delete();
        });

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [
                $this->studio->project->id,
                $this->studio->clientDocument->id,
            ]))
            ->assertForbidden();
    }

    /**
     * «Yalnız öz layihələri» işçisi üzv OLMADIĞI layihənin faylını endirə bilməz.
     *
     * Heyət tərəfi `route('files.download')` üzərindəndir və `ProjectFilePolicy`
     * + `ScopesProjectDomain` ilə qorunur. Vizualizator `otherProject`-in üzvü
     * deyil (StudioWorld qəsdən belə qurur).
     */
    public function test_staff_limited_to_own_projects_cannot_download_a_foreign_projects_file(): void
    {
        $foreignFile = $this->inTenant(fn () => ProjectFile::create([
            'project_id' => $this->studio->otherProject->id,
            'category' => 'plan',
            'visibility' => FileVisibility::Internal->value,
            'title' => 'Yad layihənin cizgisi',
            'file_path' => 'project-files/yad.dwg',
        ]));

        Storage::disk('public')->put('project-files/yad.dwg', 'yad cizgi');

        $this->asStaff('visualizer');
        $this->get(route('files.download', $foreignFile))->assertForbidden();

        // Üzv olduğu layihənin faylını isə endirə bilir — yoxlama hədsiz dar olmasın.
        Storage::disk('public')->put($this->studio->internalFile->file_path, 'öz cizgi');

        $this->asStaff('designer');
        $this->get(route('files.download', $this->studio->internalFile))->assertOk();
    }

    /**
     * Fayl adı / id-si ilə yol aşma (path traversal) mümkün olmamalıdır.
     *
     * İki vektor yoxlanılır:
     *  1. marşrut parametri — `{document}`/`{file}` yalnız ƏLAQƏ üzərindən həll
     *     olunur (`$project->documents()->findOrFail()`), ona görə rəqəm olmayan
     *     və ya kənar dəyər sətrə çata bilmir;
     *  2. DB-dəki `file_path` — sətir özü `../` daşısa da diskdən kənara
     *     çıxmamalıdır (Flysystem `PathTraversalDetected` atır; bunun müştəriyə
     *     500 kimi qayıtmaması ayrıca vacibdir).
     */
    public function test_a_file_path_escaping_the_disk_root_never_serves_a_file_outside_it(): void
    {
        $evil = $this->inTenant(fn () => Document::create([
            'project_id' => $this->studio->project->id,
            'type' => 'other',
            'title' => 'Yol aşma cəhdi',
            'file_path' => '../../../../.env',
            'visible_to_client' => true,
        ]));

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.download', [$this->studio->project->id, $evil->id]));

        $this->assertNotSame(200, $response->getStatusCode(), 'Disk kökündən kənar fayl verildi.');
        $this->assertLessThan(500, $response->getStatusCode(), 'Yol aşma cəhdi 500 verir — təmiz 404 olmalıdır.');

        // Marşrut parametri rəqəm deyilsə ümumiyyətlə uyğunlaşmır / tapılmır.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get('/portal/projects/'.$this->studio->project->id.'/documents/..%2F..%2Fenv/download')
            ->assertNotFound();
    }

    /**
     * SWEEP — fayl verən BÜTÜN portal marşrutları disk kökündən qaçan yola
     * 500 deyil, təmiz 404 ilə cavab verməlidir.
     *
     * Niyə sweep: `DocumentController`-də bu qəza tapıldı, amma eyni naxış
     * (`Storage::disk('public')->exists($path)`) dörd ayrı controller-dədir və
     * `exists()`-in ÖZÜ `PathTraversalDetected` atır — yəni «yoxlama var»
     * demək «qorunub» demək deyil. Yeni fayl marşrutu əlavə edən adam bu testi
     * qırmadan keçə bilməsin.
     */
    public function test_no_portal_file_route_returns_a_500_for_a_path_escaping_the_disk_root(): void
    {
        $escaping = '../../../../.env';

        $document = $this->inTenant(fn () => Document::create([
            'project_id' => $this->studio->project->id,
            'type' => 'other', 'title' => 'Qaçan sənəd',
            'file_path' => $escaping, 'visible_to_client' => true,
        ]));

        $file = $this->inTenant(fn () => ProjectFile::create([
            'project_id' => $this->studio->project->id,
            'category' => 'plan', 'visibility' => FileVisibility::ClientShared->value,
            'title' => 'Qaçan fayl', 'file_path' => $escaping,
        ]));

        $entry = $this->inTenant(fn () => $this->studio->project->diaryEntries()->create([
            'author_user_id' => $this->studio->user('designer')->id,
            'body' => 'Qaçan foto.', 'photos' => [$escaping], 'published_at' => now()->subHour(),
        ]));

        $item = $this->inTenant(function () use ($escaping) {
            $this->studio->procurementItem->update([
                'photo_path' => $escaping, 'visible_to_client' => true,
            ]);

            return $this->studio->procurementItem;
        });

        $message = $this->inTenant(fn () => ChatMessage::create([
            'project_id' => $this->studio->project->id,
            'author_type' => 'user', 'author_id' => $this->studio->user('designer')->id,
            'attachment_path' => $escaping, 'attachment_name' => 'qacan.pdf',
            'attachment_mime' => 'application/pdf', 'attachment_size' => 10,
            'kind' => ChatMessage::KIND_FILE,
        ]));

        $routes = [
            'sənəd' => route('portal.documents.download', [$this->studio->project->id, $document->id]),
            'layihə faylı' => route('portal.files.download', [$this->studio->project->id, $file->id]),
            'gündəlik fotosu' => route('portal.diary.photo', [$this->studio->project->id, $entry->id, 0]),
            'çat əlavəsi' => route('portal.chat.attachment', [$this->studio->project->id, $message->id]),
        ];

        $broken = [];

        foreach ($routes as $label => $url) {
            $status = $this->actingAs($this->studio->portalUser, 'customer')->get($url)->getStatusCode();

            if ($status >= 500) {
                $broken[] = $label.' → HTTP '.$status;
            }
        }

        $this->assertSame([], $broken, "Bu marşrutlar yol aşma cəhdində 500 verir:\n".implode("\n", $broken));

        // Komplektasiya fotosu QƏSDƏN yuxarıdaki siyahıda deyil: eyni qəza
        // `ProcurementController::photo()`-da da var, amma o fayl bu auditin
        // sahiblik sərhədindən kənardır (başqa modulun sahibi ona baxır).
        // Tapıntı hesabatda `app/Http/Controllers/Portal/ProcurementController.php:135`
        // kimi verilir; burada yalnız vəziyyət qeydə alınır ki, düzəldiləndə
        // bu şərh də silinsin və marşrut siyahıya qaytarılsın.
        $procurementStatus = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.procurement.photo', [$this->studio->project->id, $item->id]))
            ->getStatusCode();

        $this->assertContains(
            $procurementStatus,
            [404, 500],
            'Komplektasiya foto marşrutunun davranışı gözlənilməyən şəkildə dəyişdi — tapıntı yenidən qiymətləndirilməlidir.',
        );
    }

    // ══════════════════════ 4. RAZILAŞDIRMA DÖVRƏSİ ══════════════════════

    /** Tam dövrə: gözləmədə → təsdiq. Subyektin statusu da birlikdə hərəkət edir. */
    public function test_a_pending_approval_can_be_approved_by_the_client_and_moves_the_subject(): void
    {
        $approval = $this->studio->approval;

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertRedirect();

        $fresh = $this->inTenant(fn () => $approval->fresh());

        $this->assertSame(ApprovalStatus::Approved, $fresh->status);
        $this->assertNotNull($fresh->decided_at, 'Qərar tarixi yazılmadı.');
        $this->assertSame($this->studio->portalUser->id, $fresh->client_user_id);

        // Büdcə sətrinin `approval_status`-u da dəyişməlidir — əks halda məbləğ
        // `projects.debt`-ə düşmür və heyət cavablanmış sorğunu görməyə davam edir.
        $this->assertSame(
            ApprovalStatus::Approved,
            $this->inTenant(fn () => $this->studio->budgetLine->fresh()->approval_status),
            'Subyektin razılaşma statusu qərarla birlikdə hərəkət etmədi.',
        );
    }

    /**
     * Rədd = «düzəliş tələb olunur»: şərh MƏCBURİDİR və düzəliş tapşırığı yaranır.
     *
     * Qeyd: `ApprovalStatus`-da ayrıca `change_requested` halı YOXDUR — TZ §5.7
     * modelində rədd şərhlə birlikdə məhz düzəliş tələbi mənasını verir və
     * Əlavə B rule 18 üzrə göndərənə tapşırıq açır. Bu test həmin ekvivalentliyi
     * sənədləşdirir ki, «change-requested statusu yoxdur» sonradan boşluq kimi
     * oxunmasın.
     */
    public function test_a_rejection_requires_a_comment_and_spawns_a_revision_task(): void
    {
        $approval = $this->studio->approval;

        // Şərhsiz rədd validasiyadan keçmir.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'reject'])
            ->assertSessionHasErrors('comment');

        $this->assertSame(
            ApprovalStatus::Pending,
            $this->inTenant(fn () => $approval->fresh()->status),
            'Uğursuz validasiya statusu dəyişməməlidir.',
        );

        // Düzəliş tapşırığı avtomatlaşdırma qaydasıdır (`rule-18`) və sistemdə
        // `AUTOMATIONS_ENABLED` ilə bağlıdır — testdə onu AÇIQ vəziyyətdə
        // sürürük, əks halda test qaydanın deyil, konfiqin nəticəsini ölçər.
        config(['automations.enabled' => true]);
        AutomationRule::query()->create([
            'code' => 'rule-18',
            'name' => 'Revision (düzəliş) tapşırığı yarat',
            'trigger' => 'Approval rədd edilib',
            'priority' => 'high',
            'enabled' => true,
            'tenant_id' => null,
        ]);

        $tasksBefore = $this->inTenant(fn () => Task::count());

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), [
                'decision' => 'reject',
                'comment' => 'Divar materialı razılaşdırılan deyil.',
            ])
            ->assertRedirect();

        $fresh = $this->inTenant(fn () => $approval->fresh());

        $this->assertSame(ApprovalStatus::Rejected, $fresh->status);
        $this->assertSame('Divar materialı razılaşdırılan deyil.', $fresh->comment);

        $this->assertGreaterThan(
            $tasksBefore,
            $this->inTenant(fn () => Task::count()),
            'Rədd edilmiş razılaşdırma göndərənə düzəliş tapşırığı açmadı (Əlavə B rule 18).',
        );
    }

    /**
     * ƏN VACİB — verilmiş qərar SONRADAN çevrilə bilməz.
     *
     * Yoxlama olmadan təsdiqlənmiş razılaşdırmanı «rədd edilmiş»ə çevirmək
     * mümkün idi: subyektin statusu geri qayıdır və yenidən düzəliş tapşırığı
     * yaranır. Həm HTTP qatı (403), həm də servisin özü (istisna) bağlıdır —
     * ikisi ayrı-ayrı sürülür, çünki servisə portaldan başqa yerlərdən də gəlirlər.
     */
    public function test_a_decided_approval_cannot_be_flipped_afterwards(): void
    {
        $approval = $this->studio->approval;

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertRedirect();

        $decidedAt = $this->inTenant(fn () => $approval->fresh()->decided_at);

        // HTTP: təkrar qərar 403.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), [
                'decision' => 'reject',
                'comment' => 'Fikrimi dəyişdim.',
            ])
            ->assertForbidden();

        // Təkrar TƏSDİQ də bağlıdır (eyni qərar olsa da, `decided_at` yenilənməməlidir).
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertForbidden();

        $after = $this->inTenant(fn () => $approval->fresh());

        $this->assertSame(ApprovalStatus::Approved, $after->status, 'Qərar sonradan çevrildi.');
        $this->assertEquals($decidedAt, $after->decided_at, 'Qərar tarixi yenidən yazıldı.');
        $this->assertNull($after->comment, 'Təsdiqə sonradan rədd şərhi yazıldı.');

        // Servis qatı: controller-i tamamilə keçsək də son sədd yerindədir.
        $this->expectException(\InvalidArgumentException::class);

        $this->inTenant(fn () => app(ApprovalService::class)->decide(
            $approval->fresh(),
            false,
            'Servis üzərindən çevirmə cəhdi',
            $this->studio->portalUser,
        ));
    }

    /**
     * Yalnız SƏLAHİYYƏTLİ tərəf qərar verə bilər.
     *
     * Eyni studiyanın BAŞQA müştərisinin portal hesabı razılaşdırmaya toxunmamalıdır
     * (kirayəçi filtri bunu tutmur — sərhəd müştəri-layihə əlaqəsidir).
     */
    public function test_only_the_entitled_client_may_decide_an_approval(): void
    {
        $approval = $this->studio->approval;

        $this->actingAs($this->studio->secondPortalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertNotFound();

        // Başqa studiyanın müştərisi də.
        $other = StudioWorld::make('qa2approve');

        $this->actingAs($other->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertNotFound();

        $this->assertSame(
            ApprovalStatus::Pending,
            $this->inTenant(fn () => $approval->fresh()->status),
            'Səlahiyyətsiz tərəf razılaşdırmanı dəyişdi.',
        );

        // Heyət üzvü portal marşrutundan qərar verə bilməz: marşrut `customer`
        // guard-ındadır. Dəqiq cavab kodu (403, yoxsa girişə yönləndirmə)
        // burada əsas deyil — əsas olan qərarın QEYDƏ DÜŞMƏMƏSİDİR.
        $staffStatus = $this->actingAs($this->studio->user('project_manager'), 'web')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->getStatusCode();

        $this->assertContains($staffStatus, [302, 401, 403], 'Heyət üçün portal qərar marşrutu açıq görünür.');

        $this->assertSame(
            ApprovalStatus::Pending,
            $this->inTenant(fn () => $approval->fresh()->status),
            'Heyət hesabı portal marşrutundan qərar verdi.',
        );
    }

    /** Arxivlənmiş layihədə qərar bağlıdır — oxumaq olar, yazmaq olmaz. */
    public function test_an_approval_of_an_archived_project_cannot_be_decided(): void
    {
        $this->inTenant(fn () => $this->studio->project->update([
            'status' => ProjectStatus::Archived->value,
        ]));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $this->studio->approval), ['decision' => 'approve'])
            ->assertForbidden();

        // Siyahı isə açıq qalır (arxiv müştəri üçün tarixçədir).
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.approvals', $this->studio->project->id))
            ->assertOk();
    }

    /**
     * Admin panelindəki razılaşdırma siyahısı HƏM kirayəçiyə, HƏM «yalnız öz
     * layihələri» qaydasına görə süzülməlidir.
     *
     * Razılaşdırmanın arxasında büdcə sətri və MƏBLƏĞ dayanır, yəni süzgəcin
     * əskikliyi həm də maliyyə məlumatının sızmasıdır. `view()` policy-si sətri
     * bağlayır, siyahı isə policy-dən keçmir — ona görə sorğu qatı yoxlanılır.
     */
    public function test_the_approval_list_is_scoped_by_tenant_and_by_own_projects_only(): void
    {
        $other = StudioWorld::make('qa2scope');

        // Eyni studiyanın, üzv OLMADIĞI layihəsinin razılaşdırması.
        $foreign = $this->inTenant(fn () => Approval::create([
            'approvable_type' => 'budget_line',
            'approvable_id' => $this->studio->budgetLine->id,
            'project_id' => $this->studio->otherProject->id,
            'requested_by_user_id' => $this->studio->user('owner')->id,
            'status' => ApprovalStatus::Pending->value,
        ]));

        // Dizayner `otherProject`-in üzvü deyil (StudioWorld qəsdən belə qurur).
        $this->asStaff('designer');

        $visible = ApprovalResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($this->studio->approval->id, $visible, 'Öz layihəsinin razılaşdırması siyahıda yoxdur.');
        $this->assertNotContains(
            $foreign->id,
            $visible,
            'Üzv olmadığı layihənin razılaşdırması siyahıda görünür — məbləğ sızır.',
        );

        // Başqa studiyanın sətri heç bir halda görünməməlidir.
        $this->assertNotContains($other->approval->id, $visible, 'Başqa studiyanın razılaşdırması siyahıda görünür.');

        // Sahibkar (own=false) öz studiyasının hər ikisini görür, yadını yox.
        $this->asStaff('owner');
        $ownerVisible = ApprovalResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($foreign->id, $ownerVisible, 'Sahibkar öz studiyasının razılaşdırmasını görmür.');
        $this->assertNotContains($other->approval->id, $ownerVisible, 'Sahibkar yad studiyanın sətrini görür.');
    }

    // ════════════ 5. MÜƏLLİF NƏZARƏTİ GÜNDƏLİYİ («Müəllif nəzarəti») ════════════

    /**
     * Müəllif HƏMİŞƏ serverdən götürülür — formadan yox.
     *
     * Gündəlik qeydi obyektdə kimin olduğunun rəsmi izidir; müəllifi formadan
     * qəbul etmək onu mənasız edir (hər kəs qeydi başqasının adına yaza bilər).
     * İki şey ayrıca sürülür: (1) sxemdə ümumiyyətlə müəllif sahəsi YOXDUR,
     * (2) forma məlumatına zorla müəllif id-si yazsan da qeyd cari istifadəçiyə
     * yazılır (`mutateDataUsing` şərtsiz üzərinə yazır).
     */
    public function test_the_diary_author_is_always_taken_from_the_server_never_from_the_form(): void
    {
        $designer = $this->asStaff('designer');
        $someoneElse = $this->studio->user('owner');

        $fieldNames = collect(
            (new \ReflectionMethod(DiaryRelationManager::class, 'form'))
                ->invoke(
                    (new \ReflectionClass(DiaryRelationManager::class))->newInstanceWithoutConstructor(),
                    new Schema,
                )
                ->getComponents()
        )->map(fn ($component) => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->all();

        $this->assertNotContains(
            'author_user_id',
            $fieldNames,
            'Formada müəllif sahəsi var — müəllif redaktə olunan məlumata çevrilib.',
        );

        // Forma məlumatına yad müəllif id-si «enjekt» etmək cəhdi.
        $this->relationManager(DiaryRelationManager::class)
            ->mountTableAction('create')
            ->setTableActionData([
                'body' => 'Müəllifi saxtalaşdırmaq cəhdi.',
                'published_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->set('mountedActions.0.data.author_user_id', $someoneElse->id)
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $entry = $this->inTenant(fn () => $this->studio->project->diaryEntries()->latest('id')->first());

        $this->assertSame(
            $designer->id,
            $entry->author_user_id,
            'Müəllif formadan gələn dəyərlə yazıldı — server tərəfli təyin işləmir.',
        );
    }

    /**
     * Dərc olunmuş qeyd və fotoları müştəri portalda GÖRÜR, qaralama isə yox.
     *
     * Qaralama dizaynerin iş qeydidir: portala düşsə müştəri yarımçıq
     * formulasiyaları oxuyar.
     */
    public function test_published_diary_entries_are_visible_to_the_client_and_drafts_are_not(): void
    {
        [$published, $draft] = $this->inTenant(fn () => [
            $this->studio->project->diaryEntries()->create([
                'author_user_id' => $this->studio->user('designer')->id,
                'body' => 'DƏRC OLUNMUŞ qeyd mətni.',
                'photos' => ['diary-photos/dərc.jpg'],
                'published_at' => now()->subDay(),
            ]),
            $this->studio->project->diaryEntries()->create([
                'author_user_id' => $this->studio->user('designer')->id,
                'body' => 'QARALAMA qeyd mətni.',
                'photos' => ['diary-photos/qaralama.jpg'],
                'published_at' => null,
            ]),
        ]);

        Storage::disk('public')->put('diary-photos/dərc.jpg', 'dərc olunmuş şəkil');
        Storage::disk('public')->put('diary-photos/qaralama.jpg', 'qaralama şəkil');

        $page = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->project->id))
            ->assertOk();

        $html = $page->getContent();

        $this->assertStringContainsStringQuietly('DƏRC OLUNMUŞ qeyd mətni.', $html, 'Dərc olunmuş qeyd portalda görünmür.');
        $this->assertStringNotContainsStringQuietly('QARALAMA qeyd mətni.', $html, 'Qaralama qeyd portala sızır.');

        // Müəllifin adı da göstərilir — «müəllif nəzarəti» qeydinin mənası budur.
        $this->assertStringContainsStringQuietly(
            $this->studio->user('designer')->name,
            $html,
            'Qeydin müəllifi portalda göstərilmir.',
        );

        // Dərc olunmuş qeydin fotosu açılır, qaralamanın fotosu YOX.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project->id, $published->id, 0]))
            ->assertOk();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project->id, $draft->id, 0]))
            ->assertNotFound();
    }

    /** Yad müştərinin gündəliyi və fotosu bağlıdır (kirayəçi filtri tutmur). */
    public function test_a_client_cannot_read_the_diary_of_another_clients_project(): void
    {
        $foreign = $this->inTenant(fn () => $this->studio->otherProject->diaryEntries()->create([
            'author_user_id' => $this->studio->user('owner')->id,
            'body' => 'Yad layihənin qeydi.',
            'photos' => ['diary-photos/yad.jpg'],
            'published_at' => now()->subDay(),
        ]));

        Storage::disk('public')->put('diary-photos/yad.jpg', 'yad şəkil');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->otherProject->id))
            ->assertNotFound();

        // Öz layihəsinin marşrutuna yad qeydin id-sini yazmaq da işləməməlidir.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary.photo', [$this->studio->project->id, $foreign->id, 0]))
            ->assertNotFound();
    }

    // ══════════════════════════════ 6. GÖRÜŞLƏR ══════════════════════════════

    /** Tam dövrə: yarat → redaktə et → ləğv et (sil). */
    public function test_a_meeting_can_be_created_edited_and_cancelled(): void
    {
        $owner = $this->asStaff('owner');

        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->studio->project->id,
                'title' => 'QA2 kickoff',
                'starts_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->setTime(11, 0)->format('Y-m-d H:i:s'),
                'participants' => [$owner->id, $this->studio->user('designer')->id],
                'location' => 'Ofis',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $meeting = $this->inTenant(fn () => Meeting::where('title', 'QA2 kickoff')->first());

        $this->assertNotNull($meeting, 'Görüş yaradılmadı.');
        $this->assertSame($this->studio->tenant->id, $meeting->tenant_id, 'Görüş studiyaya ştamplanmadı.');

        Livewire::test(EditMeeting::class, ['record' => $meeting->getKey()])
            ->fillForm(['title' => 'QA2 kickoff (yenilənib)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('QA2 kickoff (yenilənib)', $this->inTenant(fn () => $meeting->fresh()->title));

        // Ləğv = silmə: `Meeting`-də status sütunu yoxdur, ləğv redaktə
        // səhifəsinin `DeleteAction`-ı ilə edilir.
        Livewire::test(EditMeeting::class, ['record' => $meeting->getKey()])
            ->callAction('delete');

        $this->assertNull($this->inTenant(fn () => Meeting::find($meeting->getKey())), 'Görüş ləğv edilmədi.');
    }

    /**
     * Təqvim ardıcıllığı: bitmə vaxtı başlanğıcdan ƏVVƏL ola bilməz.
     *
     * Formadaki `->after('starts_at')` yalnız admin panelini qoruyur; model
     * səviyyəsindəki `saving` hook-u isə idxal, avtomatlaşdırma və gələcək API
     * üçün son siperdir. İkisi ayrıca sürülür.
     */
    public function test_a_meeting_cannot_end_before_it_starts_in_the_form_or_in_the_model(): void
    {
        $this->asStaff('owner');

        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->studio->project->id,
                'title' => 'Mənfi uzunluqlu görüş',
                'starts_at' => now()->addDay()->setTime(15, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->setTime(14, 0)->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasFormErrors(['ends_at']);

        $this->expectException(\RuntimeException::class);

        $this->inTenant(fn () => Meeting::create([
            'project_id' => $this->studio->project->id,
            'title' => 'Modeldən keçmə cəhdi',
            'starts_at' => now()->addDay()->setTime(15, 0),
            'ends_at' => now()->addDay()->setTime(14, 0),
        ]));
    }

    /**
     * Forma BAŞQA STUDİYANIN layihəsini və işçisini TƏKLİF ETMƏMƏLİDİR, həm də
     * zorla yazılan yad id-ni QƏBUL ETMƏMƏLİDİR.
     *
     * Filament Select seçilmiş dəyəri `options()` siyahısına görə yoxlayır, yəni
     * siyahının daralması həm UI, həm də server validasiyasıdır.
     */
    public function test_the_meeting_form_offers_and_accepts_only_this_studios_projects_and_people(): void
    {
        $other = StudioWorld::make('qa2meet');

        $this->asStaff('owner');

        // Yad studiyanın layihəsi ilə görüş yaratmaq cəhdi rədd olunur.
        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $other->project->id,
                'title' => 'Yad studiyanın görüşü',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasFormErrors(['project_id']);

        $this->assertNull(
            app(TenantContext::class)->actingAs($other->tenant->id, fn () => Meeting::where('title', 'Yad studiyanın görüşü')->first()),
            'Yad studiyanın layihəsinə görüş yaradıldı.',
        );

        // Yad studiyanın işçisi iştirakçı kimi qəbul edilməməlidir.
        //
        // `participants` çoxseçimli Select-dir və `options()` closure ilə verilir;
        // Filament belə halda avtomatik `in` qaydası ƏLAVƏ ETMİR, yəni seçim
        // siyahısının daralması yalnız UI-dır. Livewire yükü əl ilə qurulan
        // sorğu (və ya gələcək API) yad studiyanın `users.id`-sini görüşün
        // iştirakçı massivinə yazdıra bilir.
        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->studio->project->id,
                'title' => 'Yad iştirakçı cəhdi',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'participants' => [$other->user('designer')->id],
            ])
            ->call('create')
            // Qayda hər elementə tətbiq olunduğu üçün xəta açarı da elementindir.
            ->assertHasFormErrors(['participants.0']);

        $this->assertNull(
            $this->inTenant(fn () => Meeting::where('title', 'Yad iştirakçı cəhdi')->first()),
            'Yad studiyanın işçisi iştirakçı kimi yazıldı.',
        );
    }

    /**
     * «Yalnız öz layihələri» rolu üzv olmadığı layihəni nə siyahıda görür, nə də
     * ona görüş yarada bilir.
     *
     * Əks halda işçi görüş yaradır, sonra onu itirir — nə siyahıda, nə redaktədə.
     */
    public function test_meetings_are_scoped_to_the_projects_a_limited_role_belongs_to(): void
    {
        $foreignMeeting = $this->inTenant(fn () => Meeting::create([
            'project_id' => $this->studio->otherProject->id,
            'title' => 'Üzv olmadığı layihənin görüşü',
            'starts_at' => now()->addDay(),
        ]));

        $ownMeeting = $this->inTenant(fn () => Meeting::create([
            'project_id' => $this->studio->project->id,
            'title' => 'Öz layihəsinin görüşü',
            'starts_at' => now()->addDay(),
        ]));

        $this->asStaff('designer');

        $visible = MeetingResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($ownMeeting->id, $visible, 'Öz layihəsinin görüşü siyahıda yoxdur.');
        $this->assertNotContains($foreignMeeting->id, $visible, 'Üzv olmadığı layihənin görüşü siyahıda görünür.');

        // Forma dropdown-u da eyni daralmaya tabedir.
        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->studio->otherProject->id,
                'title' => 'Üzv olmadığı layihəyə görüş',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasFormErrors(['project_id']);
    }

    // ═══════════════════════════════ 7. ÇAT ═══════════════════════════════

    /** Lent layihə üzrə bağlıdır: bir layihənin pollinqi başqasının mesajını verməz. */
    public function test_chat_messages_are_scoped_per_project(): void
    {
        $foreignMessage = $this->inTenant(fn () => ChatMessage::create([
            'project_id' => $this->studio->otherProject->id,
            'author_type' => 'user',
            'author_id' => $this->studio->user('owner')->id,
            'body' => 'YAD LAYİHƏNİN MESAJI',
        ]));

        $payload = $this->actingAs($this->studio->portalUser, 'customer')
            ->getJson(route('portal.chat.poll', $this->studio->project->id).'?after=0')
            ->assertOk()
            ->json('messages');

        $ids = array_column($payload, 'id');

        $this->assertContains($this->studio->chatMessage->id, $ids, 'Öz lentinin mesajı gəlmir.');
        $this->assertNotContains($foreignMessage->id, $ids, 'Yad layihənin mesajı lentə düşür.');

        // Yad layihənin lentinə birbaşa müraciət də bağlıdır.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->getJson(route('portal.chat.poll', $this->studio->otherProject->id))
            ->assertNotFound();

        // Axtarış da layihə ilə məhdudlaşır.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat', $this->studio->project->id).'?q=YAD')
            ->assertOk()
            ->assertDontSee('YAD LAYİHƏNİN MESAJI', false);
    }

    /**
     * SƏNƏDLƏŞDİRMƏ — daxili (yalnız heyət üçün) çat mesajı ANLAYIŞI YOXDUR.
     *
     * Bu, tapıntı deyil, qəsdən qeydə alınan boşluqdur: `chat_messages`
     * cədvəlində nə `is_internal`, nə `visibility`, nə də ekvivalent sütun var
     * və `ChatService` belə bir süzgəc tətbiq etmir. Yəni layihə lentində yazılan
     * HƏR mesaj müştəriyə görünür — heyət «öz aralarında» yazdığını düşünüb
     * müştəri qarşısında məlumat açıqlaya bilər.
     *
     * Test sütun əlavə olunan gün QIRILIR ki, süzgəcin də həqiqətən tətbiq
     * olunduğu yoxlanılsın (sütun var, süzgəc yox — ən pis hal).
     */
    public function test_there_is_no_internal_staff_only_chat_concept(): void
    {
        $internalColumns = array_values(array_filter(
            ['is_internal', 'internal', 'visibility', 'staff_only', 'audience'],
            fn (string $column) => SchemaFacade::hasColumn('chat_messages', $column),
        ));

        $this->assertSame(
            [],
            $internalColumns,
            'Çat mesajlarında daxili/xarici ayrımı üçün sütun peyda olub ('.implode(', ', $internalColumns).'). '
            .'Deməli süzgəcin HƏM `ChatService`-də, HƏM portal marşrutlarında tətbiq olunduğu ayrıca sübut edilməlidir.',
        );

        // Faktiki davranışın təsdiqi: heyətin yazdığı mesaj müştəriyə görünür.
        $staffMessage = $this->inTenant(fn () => ChatMessage::create([
            'project_id' => $this->studio->project->id,
            'author_type' => 'user',
            'author_id' => $this->studio->user('designer')->id,
            'body' => 'Heyətin daxili sandığı qeyd.',
        ]));

        $ids = array_column(
            $this->actingAs($this->studio->portalUser, 'customer')
                ->getJson(route('portal.chat.poll', $this->studio->project->id).'?after=0')
                ->json('messages'),
            'id',
        );

        $this->assertContains(
            $staffMessage->id,
            $ids,
            'Davranış dəyişib — heyət mesajı müştəriyə görünmür. Daxili mesaj anlayışı əlavə olunubsa test yenilənməlidir.',
        );
    }

    /** Çat əlavəsinin tam dövrəsi: müştəri göndərir → heyət oxuyur → müştəri endirir. */
    public function test_a_chat_attachment_round_trips_between_the_portal_and_the_panel(): void
    {
        $bytes = 'QA2 çat əlavəsinin məzmunu.';

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.chat.send', $this->studio->project->id), [
                'attachment' => UploadedFile::fake()->createWithContent('olcme.pdf', $bytes),
            ])
            ->assertOk();

        $message = $this->inTenant(fn () => ChatMessage::whereNotNull('attachment_path')->latest('id')->first());

        $this->assertNotNull($message, 'Əlavəli mesaj yaradılmadı.');
        $this->assertTrue(
            Storage::disk('public')->exists($message->attachment_path),
            'Çat əlavəsi `public` diskində yoxdur: '.$message->attachment_path,
        );

        // Orijinal ad yolda deyil (təxmin edilə bilməsin), amma endirmədə qayıdır.
        $this->assertStringNotContainsStringQuietly(
            'olcme',
            $message->attachment_path,
            'Əlavənin diskdəki yolu orijinal adı daşıyır — yol kənardan təxmin edilə bilər.',
        );

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.chat.attachment', [$this->studio->project->id, $message->id]));

        $response->assertOk();
        $this->assertSame($bytes, $response->streamedContent(), 'Çat əlavəsinin baytları uyğun gəlmir.');
        $this->assertStringContainsString(
            'olcme.pdf',
            (string) $response->headers->get('content-disposition'),
            'Endirmədə orijinal fayl adı qaytarılmır.',
        );
    }

    /**
     * Heyət «Çat» ekranında üzv OLMADIĞI layihənin söhbətini aça bilməməlidir —
     * `projectId` sonradan dəyişdirildikdə də.
     *
     * NİYƏ AYRICA TEST: `ChatCenter::$projectId` public Livewire xassəsidir və
     * avtorizasiya yalnız `mount()`-da edilir. Livewire public xassələri HƏR
     * sorğuda gələn yükdən hidratlaşdırır, `mount()` isə YALNIZ bir dəfə işləyir.
     * Yəni ilk yüklənmədən sonra göndərilən `updateProperty` çağırışı `projectId`-ni
     * dəyişə bilir və `mount()`-daki `can('view', $project)` yoxlaması bir daha
     * icra olunmur. Söhbətin özü layihə danışığıdır — müştəri adı, mövzu, fayl
     * adları.
     */
    public function test_the_chat_center_cannot_be_pointed_at_a_project_the_user_may_not_view(): void
    {
        $designer = $this->asStaff('designer');

        // Ön şərt: dizayner `otherProject`-i görə bilmir (üzv deyil).
        $this->assertFalse(
            $designer->can('view', $this->inTenant(fn () => $this->studio->otherProject->fresh())),
            'Ön şərt pozulub: dizayner ikinci layihəni görə bilir.',
        );

        // Marşrut parametri ilə açmaq bağlıdır — bu hissə onsuz da işləyirdi.
        Livewire::test(ChatCenter::class, ['project' => $this->studio->otherProject->id]);
        $this->get(ChatCenter::getUrl().'?project='.$this->studio->otherProject->id)->assertForbidden();

        // ƏSAS HAL: ekran icazəli vəziyyətdə açılır, sonra xassə dəyişdirilir.
        $component = Livewire::test(ChatCenter::class)->set('projectId', $this->studio->otherProject->id);

        // Qeyd: müqayisə yalnız ID üzrədir — modeli assert-ə vermək qırılma
        // hesabatına bütün Eloquent obyektini tökür və oxunmaz edir.
        $activeId = $component->instance()->getActiveProject()?->getKey();

        $this->assertNotSame(
            $this->studio->otherProject->id,
            $activeId,
            'Çat ekranı üzv olmadığı layihənin söhbətini açır — `projectId` dəyişdirildikdən sonra '
            .'avtorizasiya yenidən yoxlanılmır (public Livewire xassəsi hər sorğuda hidratlaşır, '
            .'`mount()` isə yalnız bir dəfə işləyir).',
        );

        // Düzəliş hədsiz dar olmasın: ÖZ layihəsi həm marşrutla, həm xassə ilə
        // açılmalı və ekran normal render olunmalıdır.
        $this->get(ChatCenter::getUrl().'?project='.$this->studio->project->id)->assertOk();

        $own = Livewire::test(ChatCenter::class)
            ->set('projectId', $this->studio->project->id)
            ->assertOk();

        $this->assertSame(
            $this->studio->project->id,
            $own->instance()->getActiveProject()?->getKey(),
            'Öz layihəsinin söhbəti açılmır — yoxlama hədsiz dardır.',
        );
    }

    // ══════════════════ 8. DELIVERABLE VƏ PUNCH LIST ══════════════════

    /**
     * BLOKER QORUYUCUSU — «Dizayn (Deliverables)» cədvəli ümumiyyətlə RENDER
     * OLUNMALIDIR.
     *
     * Filament closure parametrlərini əvvəlcə ADA görə inject edir, ad tanınmasa
     * TİPƏ görə konteynerdən həll etməyə çalışır. `formatStateUsing(fn (?DeliverableVersionStatus $s) => ...)`
     * kimi qısa adlı parametr heç bir tanınan ada uyğun gəlmir, ona görə Filament
     * enum-u KONTEYNERDƏN yaratmağa cəhd edir və
     * «Target [App\Enums\DeliverableVersionStatus] is not instantiable» ilə
     * çökür. Nəticə: layihənin Dizayn tabı bir dənə deliverable olan kimi 500 verir.
     *
     * Düzgün ad `$state`-dir (CLAUDE.md-dəki «Filament closure parametr adları»
     * tələsinin eynisi, yalnız sütunlar üçün).
     */
    public function test_the_deliverables_table_renders_with_records(): void
    {
        $this->asStaff('project_manager');

        $deliverable = $this->inTenant(fn () => Deliverable::create([
            'project_id' => $this->studio->project->id,
            'type' => DeliverableType::Concept->value,
            'title' => 'Render yoxlaması',
            'status' => DeliverableStatus::Draft->value,
        ]));

        // Versiyası OLMAYAN sətir: `currentVersion.status` NULL-dur.
        $this->relationManager(DeliverablesRelationManager::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$deliverable]);

        // Versiyası OLAN sətir: state enum-dur.
        $this->inTenant(fn () => app(DeliverableService::class)->createVersion(
            $deliverable,
            'deliverables/render-v1.pdf',
            $this->studio->user('designer'),
            'v1',
        ));

        $this->relationManager(DeliverablesRelationManager::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$deliverable->fresh()]);
    }

    /**
     * Müştəri tərəfindən QƏBUL EDİLMİŞ deliverable sonradan sükutla dəyişdirilə
     * bilməz: nə silinir, nə də faylı əvəzlənir.
     *
     * Versiya vəziyyət maşını (`DeliverableVersionStatus`) `approved`/`locked`
     * hallarını dəyişilməz elan edir — yeni iş yalnız YENİ versiya ilə gedir.
     */
    public function test_a_deliverable_approved_by_the_client_is_locked_against_deletion_and_version_changes(): void
    {
        $this->asStaff('project_manager');

        $deliverable = $this->inTenant(fn () => Deliverable::create([
            'project_id' => $this->studio->project->id,
            'type' => DeliverableType::Concept->value,
            'title' => 'QA2 konsepti',
            'status' => DeliverableStatus::Draft->value,
        ]));

        // Versiya yarat → razılaşdırmaya göndər → müştəri təsdiqləyir.
        $version = $this->inTenant(fn () => app(DeliverableService::class)->createVersion(
            $deliverable,
            'deliverables/konsept-v1.pdf',
            $this->studio->user('designer'),
            'İlk versiya',
        ));

        $approval = $this->inTenant(fn () => app(ApprovalService::class)->request(
            $deliverable->fresh(),
            $this->studio->user('project_manager'),
        ));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $approval), ['decision' => 'approve'])
            ->assertRedirect();

        $deliverable = $this->inTenant(fn () => $deliverable->fresh());

        $this->assertSame(DeliverableStatus::Approved, $deliverable->status, 'Deliverable təsdiqlənmiş statusa keçmədi.');

        $version = $this->inTenant(fn () => $version->fresh());

        $this->assertSame(
            DeliverableVersionStatus::Locked,
            $version->status,
            'Təsdiqdən sonra versiya kilidlənmədi — fayl hələ dəyişdirilə bilər.',
        );
        $this->assertTrue($version->status->isImmutable());

        // Kilidli versiyadan heç bir keçid yoxdur (yalnız yeni versiya).
        foreach (DeliverableVersionStatus::cases() as $target) {
            $this->assertFalse(
                $version->status->canTransitionTo($target),
                'Kilidli versiyadan «'.$target->value.'»-a keçid açıq qalıb.',
            );
        }

        // Silmə action-ı təsdiqlənmiş deliverable üçün gizlidir.
        $this->asStaff('project_manager');
        $this->relationManager(DeliverablesRelationManager::class)
            ->assertTableActionHidden('delete', $deliverable->getKey());

        // Razılaşdırmaya təkrar göndərmək də bağlıdır (kilidli versiya).
        $this->relationManager(DeliverablesRelationManager::class)
            ->assertTableActionHidden('sendForApproval', $deliverable->getKey());
    }

    /**
     * Punch list qüsuru: fotonun tam dövrəsi + statusu kimin dəyişə biləcəyi.
     *
     * Portalda punch list marşrutu YOXDUR, ona görə dövrə diskdə bitir; əsas
     * risk elə budur ki, foto yanlış diskə düşsün və paneldəki cədvəl boş
     * göstərsin.
     */
    public function test_a_punch_list_photo_lands_on_the_public_disk_and_status_changes_respect_the_policy(): void
    {
        $this->asStaff('project_manager');

        $this->relationManager(PunchListRelationManager::class)
            ->mountTableAction('create')
            ->setTableActionData([
                'title' => 'Kafel çatı',
                'room' => 'Mətbəx',
                'priority' => PunchIssuePriority::High->value,
                'status' => PunchIssueStatus::Open->value,
                'photo_url' => UploadedFile::fake()->image('qusur.jpg', 20, 20),
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $issue = $this->inTenant(fn () => PunchListIssue::where('title', 'Kafel çatı')->first());

        $this->assertNotNull($issue, 'Qüsur yaradılmadı.');
        $this->assertNotNull($issue->photo_url, 'Foto yolu yazılmadı.');
        $this->assertTrue(
            Storage::disk('public')->exists($issue->photo_url),
            'Punch list fotosu `public` diskində yoxdur: '.$issue->photo_url,
        );

        // Statusu dəyişmək YAZMA əməliyyatıdır: üzv olmayan rol edə bilməməlidir.
        $foreignIssue = $this->inTenant(fn () => PunchListIssue::create([
            'project_id' => $this->studio->otherProject->id,
            'title' => 'Yad layihənin qüsuru',
            'status' => PunchIssueStatus::Open->value,
            'priority' => PunchIssuePriority::Normal->value,
        ]));

        $designer = $this->asStaff('designer');

        $this->assertTrue(
            $designer->can('update', $this->inTenant(fn () => $issue->fresh())),
            'Öz layihəsinin qüsurunu dəyişə bilmir.',
        );
        $this->assertFalse(
            $designer->can('update', $this->inTenant(fn () => $foreignIssue->fresh())),
            'Üzv olmadığı layihənin qüsurunu dəyişə bilir.',
        );

        // Cədvəl də üzv olmadığı layihənin qüsurunu göstərmir (RM layihəyə bağlıdır).
        $this->relationManager(PunchListRelationManager::class)
            ->assertCanSeeTableRecords([$issue])
            ->assertCanNotSeeTableRecords([$foreignIssue]);
    }
}
