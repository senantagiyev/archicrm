<?php

namespace Tests\Feature;

use App\Enums\DiaryEntryKind;
use App\Enums\PunchIssueStatus;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\DiaryRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\PunchListRelationManager;
use App\Models\DiaryEntry;
use App\Models\PunchListIssue;
use App\Models\User;
use App\Notifications\PunchIssueAssigned;
use App\Services\Supervision\PunchIssueTimeline;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Müəllif nəzarəti: «Səfərlər və hesabatlar» + «İradlar» (TZ §8, qəbul
 * meyarı 10, ssenari 3).
 *
 * Ssenari: əməkdaş telefondan irad yaradır (foto, məsul, müddət) → məsul
 * şəxs onu «Mənə təyin olunanlar»da görür, nəticəni foto + qeydlə yazır →
 * yoxlayan bağlayır; bağlanma və dəyişiklik tarixçəsi saxlanılır, fotolar
 * yenidən yüklənmədən başqa yerdə (səfərdən irada) işlənir.
 */
class SupervisionTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->studio = StudioWorld::make('supervision');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_an_issue_is_created_from_the_short_form_with_photo_responsible_and_due_date(): void
    {
        Notification::fake();
        $manager = $this->asStaff('project_manager');
        $designer = $this->studio->user('designer');

        $this->issues()
            ->assertSee('İradlar')
            ->callTableAction('create', data: [
                'title' => 'Mətbəxdə kafel çatlayıb',
                'photos' => [UploadedFile::fake()->image('kafel.jpg', 40, 40)],
                'responsible_user_id' => $designer->id,
                'due_date' => now()->addDays(3)->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $issue = $this->inTenant(fn () => PunchListIssue::sole());

        $this->assertSame('Mətbəxdə kafel çatlayıb', $issue->title);
        $this->assertSame($designer->id, $issue->responsible_user_id);
        $this->assertSame(now()->addDays(3)->toDateString(), $issue->due_date->toDateString());
        $this->assertSame(PunchIssueStatus::Assigned, $issue->status, 'Məsul şəxslə açılan irad dərhal təyin olunmuş sayılır.');
        $this->assertSame($manager->id, $issue->created_by_user_id);
        $this->assertCount(1, $issue->photoPaths());
        Storage::disk('public')->assertExists($issue->photoPaths()[0]);

        Notification::assertSentTo($designer, PunchIssueAssigned::class);
    }

    public function test_the_responsible_person_sees_the_issue_records_the_result_and_the_history_is_kept(): void
    {
        $designer = $this->studio->user('designer');
        $issue = $this->issue('Qapı tənzimlənməyib', $designer);
        $someoneElse = $this->issue('Təchizatın iradı', $this->studio->user('procurement'));

        $this->asStaff('designer');

        $this->issues()
            ->filterTable('assigned_to_me')
            ->assertCanSeeTableRecords([$issue])
            ->assertCanNotSeeTableRecords([$someoneElse])
            ->assertTableActionVisible('recordResolution', $issue)
            ->assertTableActionHidden('closeIssue', $issue)
            ->callTableAction('recordResolution', $issue, data: [
                'resolution_note' => 'Menteşələr tənzimləndi, qapı sərbəst bağlanır.',
                'resolved_photos' => [UploadedFile::fake()->image('qapi.jpg', 40, 40)],
            ])
            ->assertHasNoTableActionErrors();

        $issue->refresh();
        $this->assertSame(PunchIssueStatus::Resolved, $issue->status);
        $this->assertSame('Menteşələr tənzimləndi, qapı sərbəst bağlanır.', $issue->resolution_note);
        $this->assertSame($designer->id, $issue->resolved_by_user_id);
        $this->assertNotNull($issue->resolved_at);
        $this->assertCount(1, $issue->resolvedPhotoPaths());
        Storage::disk('public')->assertExists($issue->resolvedPhotoPaths()[0]);

        // Yoxlayan bağlayır.
        $manager = $this->asStaff('project_manager');

        $this->issues()
            ->assertTableActionHidden('recordResolution', $issue)
            ->callTableAction('closeIssue', $issue, data: ['comment' => 'Yoxladım, qəbul edildi.'])
            ->assertHasNoTableActionErrors();

        $issue->refresh();
        $this->assertSame(PunchIssueStatus::Closed, $issue->status);
        $this->assertSame($manager->id, $issue->closed_by_user_id);
        $this->assertNotNull($issue->closed_at);

        // Tarixçə: yaradılış → nəticə → bağlanma + şərh, kim və nə vaxt.
        $timeline = $this->inTenant(fn () => app(PunchIssueTimeline::class)->for($issue->fresh()));
        $titles = $timeline->pluck('title')->all();

        $this->assertContains('İrad qeydə alındı', $titles);
        $this->assertContains('Düzəlişin nəticəsi qeyd edildi', $titles);
        $this->assertContains('İrad bağlandı', $titles);
        $this->assertContains('Şərh', $titles);
        $this->assertSame($designer->name, $timeline->firstWhere('title', 'Düzəlişin nəticəsi qeyd edildi')['actor']);
        $this->assertSame($manager->name, $timeline->firstWhere('title', 'İrad bağlandı')['actor']);

        // Panel: müzakirə pəncərəsində tarixçə və fotolar görünür, şərh yazılır.
        $this->issues()
            ->mountTableAction('history', $issue)
            ->assertMountedActionModalSee([
                'Menteşələr tənzimləndi, qapı sərbəst bağlanır.',
                'İrad bağlandı',
                'Yoxladım, qəbul edildi.',
                'Düzəlişdən sonra (1)',
                'Endir',
            ])
            ->setTableActionData(['comment' => 'Müştəri də baxdı, razıdır.'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertTrue($issue->comments()->where('body', 'Müştəri də baxdı, razıdır.')->exists());
    }

    public function test_returning_for_rework_requires_a_reason_and_reopens_the_issue(): void
    {
        $designer = $this->studio->user('designer');
        $issue = $this->issue('Plintus qopub', $designer);
        $this->inTenant(fn () => $issue->recordResolution($designer, 'Yapışdırıldı.'));

        $this->asStaff('project_manager');

        $this->issues()
            ->callTableAction('returnForRework', $issue, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame(PunchIssueStatus::Resolved, $issue->fresh()->status);

        $this->issues()
            ->callTableAction('returnForRework', $issue, data: ['reason' => 'Künc hələ də aralıdır.'])
            ->assertHasNoTableActionErrors();

        $issue->refresh();
        $this->assertSame(PunchIssueStatus::Rejected, $issue->status);
        $this->assertTrue($issue->comments()->where('body', 'like', '%Künc hələ də aralıdır.%')->exists());

        // Yenidən işə qaytarılmış irad məsul şəxsin siyahısındadır.
        $this->asStaff('designer');
        $this->issues()
            ->filterTable('assigned_to_me')
            ->assertCanSeeTableRecords([$issue])
            ->assertTableActionVisible('recordResolution', $issue);
    }

    public function test_an_issue_raised_on_a_site_visit_reuses_its_photos_without_reupload(): void
    {
        $this->asStaff('project_manager');
        $designer = $this->studio->user('designer');

        $this->visits()
            ->assertSee('Səfərlər və hesabatlar')
            ->callTableAction('create', data: [
                'kind' => DiaryEntryKind::Visit->value,
                'visited_on' => today()->toDateString(),
                'body' => 'Obyektə səfər: mətbəx və dəhliz yoxlanıldı.',
                'status' => 'new',
                'photos' => [
                    UploadedFile::fake()->image('metbex.jpg', 40, 40),
                    UploadedFile::fake()->image('dehliz.jpg', 40, 40),
                ],
            ])
            ->assertHasNoTableActionErrors();

        $visit = $this->inTenant(fn () => DiaryEntry::sole());
        $this->assertSame(DiaryEntryKind::Visit, $visit->kind);
        $this->assertSame(today()->toDateString(), $visit->visited_on->toDateString());
        [$kitchen, $hall] = $visit->photoPaths();
        $filesBefore = count(Storage::disk('public')->allFiles());

        $this->visits()
            ->callTableAction('createIssue', $visit, data: [
                'title' => 'Dəhlizdə plintus qopub',
                'photos' => [$hall],
                'responsible_user_id' => $designer->id,
                'due_date' => now()->addWeek()->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $issue = $this->inTenant(fn () => PunchListIssue::sole());
        $this->assertSame($visit->id, $issue->diary_entry_id);
        $this->assertSame([$hall], $issue->photoPaths(), 'Foto yolu irada keçmədi.');
        $this->assertSame(PunchIssueStatus::Assigned, $issue->status);
        $this->assertSame($filesBefore, count(Storage::disk('public')->allFiles()), 'Foto yenidən yükləndi / kopyalandı.');

        $this->assertNotContains($kitchen, $issue->photoPaths());

        // Başqa qeydin (yad) faylı brauzerdən göndərilsə də irada düşmür.
        $this->visits()->callTableAction('createIssue', $visit, data: [
            'title' => 'Yad foto',
            'photos' => ['punchlist/yad.jpg'],
        ]);

        $leaked = $this->inTenant(fn () => PunchListIssue::where('title', 'Yad foto')->get())
            ->contains(fn (PunchListIssue $other) => in_array('punchlist/yad.jpg', $other->photoPaths(), true));
        $this->assertFalse($leaked, 'Yad fayl yolu irada yazıldı.');
    }

    public function test_legacy_single_photo_columns_stay_readable(): void
    {
        $issue = $this->inTenant(fn () => PunchListIssue::create([
            'project_id' => $this->studio->project->id,
            'title' => 'Köhnə qüsur',
            'photo_url' => 'punchlist/kohne.jpg',
            'resolved_photo_url' => 'punchlist/kohne-hell.jpg',
        ]));

        $this->assertSame(['punchlist/kohne.jpg'], $issue->photoPaths());
        $this->assertSame(['punchlist/kohne-hell.jpg'], $issue->resolvedPhotoPaths());
    }

    // ═══════════════════════ Köməkçilər ═══════════════════════

    private function issue(string $title, ?User $responsible): PunchListIssue
    {
        return $this->inTenant(fn () => PunchListIssue::create([
            'project_id' => $this->studio->project->id,
            'title' => $title,
            'responsible_user_id' => $responsible?->id,
            'status' => PunchIssueStatus::Assigned->value,
            'due_date' => now()->addDays(2)->toDateString(),
        ]));
    }

    private function issues(): Testable
    {
        return Livewire::test(PunchListRelationManager::class, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
        ]);
    }

    private function visits(): Testable
    {
        return Livewire::test(DiaryRelationManager::class, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
        ]);
    }

    private function asStaff(string $role): User
    {
        $user = $this->studio->user($role);

        $this->flushSession();
        while (SupportRedirects::$redirectorCacheStack !== []) {
            app()->instance('redirect', array_pop(SupportRedirects::$redirectorCacheStack));
        }

        AccessMatrix::flushCache();
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->studio->tenant->id);

        return $user;
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, $callback);
    }
}
