<?php

namespace Tests\Feature;

use App\Enums\DiaryEntryStatus;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\RelationManagers\DiaryRelationManager;
use App\Models\DiaryEntry;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\TranslationSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Müəllif nəzarəti qeydinin statusu (Yeni → Baxılır → Baxıldı → İcra edildi /
 * Ləğv edildi): paneldə rəngli nişan, süzgəc, formada və sətir əməliyyatı ilə
 * dəyişmə, portalda müştərinin dilində göstərilmə.
 */
class DiaryStatusTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('diarystatus');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_a_new_entry_starts_as_new_by_default(): void
    {
        $entry = $this->entry('Status verilməyən qeyd');

        $this->assertSame(DiaryEntryStatus::New, $entry->status);
        $this->assertSame(DiaryEntryStatus::New, $this->inTenant(fn () => $entry->fresh())->status);
    }

    public function test_every_status_has_a_label_and_a_colour(): void
    {
        foreach (DiaryEntryStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertNotSame('', $status->color());
        }

        $this->assertSame('Baxılır', DiaryEntryStatus::UnderReview->label());
        $this->assertSame('Baxıldı', DiaryEntryStatus::Reviewed->label());
        $this->assertSame('İcra edildi', DiaryEntryStatus::Done->label());
    }

    public function test_the_status_is_set_from_the_create_form(): void
    {
        $this->asStaff('designer');

        $this->relationManager()
            ->mountTableAction('create')
            ->setTableActionData([
                'body' => 'Divarın rəngi layihəyə uyğun deyil.',
                'status' => DiaryEntryStatus::UnderReview->value,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $entry = $this->inTenant(fn () => $this->studio->project->diaryEntries()->latest('id')->first());

        $this->assertSame(DiaryEntryStatus::UnderReview, $entry->status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->asStaff('designer');

        $this->relationManager()
            ->mountTableAction('create')
            ->setTableActionData([
                'body' => 'Saxta status cəhdi.',
                'status' => 'hacked',
            ])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['status']);
    }

    public function test_the_quick_action_changes_the_status(): void
    {
        $entry = $this->entry('Kafel tikişi əyridir');
        $this->asStaff('designer');

        $this->relationManager()
            ->callAction(TestAction::make('changeStatus')->table($entry), [
                'status' => DiaryEntryStatus::Done->value,
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(DiaryEntryStatus::Done, $this->inTenant(fn () => $entry->fresh())->status);
    }

    public function test_a_view_only_role_does_not_get_the_status_action(): void
    {
        $entry = $this->entry('Mühasib dəyişə bilməz');
        $this->asStaff('accountant');

        $this->relationManager()
            ->assertActionHidden(TestAction::make('changeStatus')->table($entry));
    }

    public function test_the_table_filters_by_status(): void
    {
        $done = $this->entry('Bitmiş irad', DiaryEntryStatus::Done);
        $pending = $this->entry('Gözləyən irad', DiaryEntryStatus::UnderReview);
        $this->asStaff('project_manager');

        $this->relationManager()
            ->assertCanSeeTableRecords([$done, $pending])
            ->filterTable('status', DiaryEntryStatus::Done->value)
            ->assertCanSeeTableRecords([$done])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_the_portal_shows_the_status_label(): void
    {
        $this->entry('Mətbəxdə irad icra olundu', DiaryEntryStatus::Done, published: true);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.diary', $this->studio->project))
            ->assertOk()
            ->assertSee('İcra edildi');
    }

    public function test_the_portal_status_is_translated_to_the_clients_language(): void
    {
        $this->seed(TranslationSeeder::class);
        $this->entry('Irad baxılır', DiaryEntryStatus::UnderReview, published: true);

        $this->actingAs($this->studio->portalUser, 'customer')
            ->withSession(['locale' => 'en'])
            ->get(route('portal.diary', $this->studio->project))
            ->assertOk()
            ->assertSee('Under review');
    }

    // ═══════════════════════ Köməkçilər ═══════════════════════

    private function entry(string $body, ?DiaryEntryStatus $status = null, bool $published = false): DiaryEntry
    {
        return $this->inTenant(fn () => $this->studio->project->diaryEntries()->create(array_filter([
            'author_user_id' => $this->studio->user('designer')->id,
            'body' => $body,
            'status' => $status?->value,
            'published_at' => $published ? now()->subHour() : null,
        ], fn ($value) => $value !== null)));
    }

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

    private function relationManager()
    {
        return Livewire::test(DiaryRelationManager::class, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => EditProject::class,
        ]);
    }
}
