<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\ProjectResource\RelationManagers\BriefAnswersRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\StagesRelationManager;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Layihə → «Komanda» tabı → «Üzv əlavə et».
 *
 * Əvvəl seçim siyahısında hər əməkdaş «Üzv» sözü ilə görünürdü (RM-də başlıq
 * atributu yox idi, Filament modelin adına düşürdü) və bir dəfəyə yalnız bir
 * nəfər əlavə olunurdu. İndi: «Ad — rol», axtarış, çoxlu seçim; yalnız bu
 * studiyanın aktiv və hələ üzv olmayan işçiləri.
 */
class ProjectMembersAttachTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private StudioWorld $foreign;

    private User $newDesigner;

    private User $inactive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('membersattach');
        $this->foreign = StudioWorld::make('membersforeign');

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $this->newDesigner = User::create([
                'name' => 'Leyla Yeni', 'email' => 'leyla@membersattach.test', 'password' => 'secret123',
                'role' => 'designer', 'is_active' => true,
            ]);
            $this->inactive = User::create([
                'name' => 'Kamran Deaktiv', 'email' => 'kamran@membersattach.test', 'password' => 'secret123',
                'role' => 'designer', 'is_active' => false,
            ]);
        });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_the_employee_dropdown_shows_names_with_roles_and_only_eligible_staff(): void
    {
        $this->asStaff('owner');

        $options = $this->attachOptions();

        $this->assertNotContains('Üzv', $options, 'Seçimlər yenə modelin adı ilə görünür.');

        $visualizer = $this->studio->user('visualizer');
        $this->assertSame($visualizer->name.' — '.StaffRole::Visualizer->label(), $options[$visualizer->id] ?? null);
        $this->assertSame('Leyla Yeni — '.StaffRole::Designer->label(), $options[$this->newDesigner->id] ?? null);

        // Artıq üzv olanlar, deaktiv işçi və başqa studiyanın işçisi siyahıda yoxdur.
        $this->assertArrayNotHasKey($this->studio->user('designer')->id, $options);
        $this->assertArrayNotHasKey($this->studio->user('procurement')->id, $options);
        $this->assertArrayNotHasKey($this->inactive->id, $options);
        foreach ($this->foreign->staff as $foreignUser) {
            $this->assertArrayNotHasKey($foreignUser->id, $options, 'Başqa studiyanın işçisi siyahıya düşdü.');
        }
    }

    public function test_several_employees_are_attached_at_once_and_appear_in_the_team_table(): void
    {
        $this->asStaff('owner');
        $visualizer = $this->studio->user('visualizer');
        $accountant = $this->studio->user('accountant');

        $this->relationManager()
            ->mountTableAction('attach')
            ->setTableActionData([
                'recordId' => [$visualizer->id, $this->newDesigner->id, $accountant->id],
                'project_role' => 'designer',
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $project = $this->studio->project->fresh();
        foreach ([$visualizer, $this->newDesigner, $accountant] as $user) {
            $this->assertSame('designer', $project->members()->whereKey($user->id)->first()?->pivot->project_role);
        }

        $this->relationManager()->assertCanSeeTableRecords([
            $visualizer, $this->newDesigner, $accountant,
            $this->studio->user('designer'), $this->studio->user('project_manager'),
        ]);
    }

    public function test_an_attached_member_with_an_own_projects_role_can_then_see_the_project(): void
    {
        $visualizer = $this->asStaff('visualizer');
        $this->assertFalse($visualizer->can('view', $this->studio->project), 'Üzv olmadan layihə görünməməlidir.');

        $this->asStaff('owner');
        $this->relationManager()
            ->mountTableAction('attach')
            ->setTableActionData(['recordId' => [$visualizer->id], 'project_role' => 'visualizer'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $visualizer = $this->asStaff('visualizer');
        $this->assertTrue($visualizer->can('view', $this->studio->project->fresh()));
        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$this->studio->project])
            ->assertCanNotSeeTableRecords([$this->studio->otherProject]);
        $this->get(ProjectResource::getUrl('edit', ['record' => $this->studio->project]))->assertOk();
    }

    public function test_a_crafted_request_cannot_attach_a_foreign_inactive_or_existing_member(): void
    {
        $this->asStaff('owner');
        $foreignUser = $this->foreign->user('designer');

        $this->relationManager()
            ->mountTableAction('attach')
            ->setTableActionData([
                'recordId' => [$foreignUser->id, $this->inactive->id, $this->studio->user('designer')->id],
                'project_role' => 'designer',
            ])
            ->callMountedTableAction();

        $members = $this->studio->project->members()->withoutGlobalScopes()->get();
        $this->assertFalse($members->contains('id', $foreignUser->id), 'Başqa studiyanın işçisi layihəyə bağlandı.');
        $this->assertFalse($members->contains('id', $this->inactive->id), 'Deaktiv işçi layihəyə bağlandı.');
        $this->assertSame(1, $members->where('id', $this->studio->user('designer')->id)->count(), 'Mövcud üzv təkrar bağlandı.');
    }

    public function test_detach_still_removes_a_member(): void
    {
        $this->asStaff('owner');
        $designer = $this->studio->user('designer');

        $this->relationManager()
            ->callTableAction('detach', $designer)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($this->studio->project->members()->whereKey($designer->id)->exists());
    }

    public function test_brief_is_the_first_tab_and_stages_the_second(): void
    {
        $relations = ProjectResource::getRelations();

        $this->assertSame(BriefAnswersRelationManager::class, $relations[0]);
        $this->assertSame(StagesRelationManager::class, $relations[1]);
    }

    /** @return array<int|string, string> */
    private function attachOptions(): array
    {
        $component = $this->relationManager()->mountTableAction('attach');
        $livewire = $component->instance();

        $select = $livewire->getSchema($livewire->getMountedActionSchemaName())
            ->getComponent(fn ($c) => $c instanceof Select && $c->getName() === 'recordId', withHidden: true);

        $this->assertNotNull($select, 'Əməkdaş seçimi formada tapılmadı.');

        return $select->getOptions();
    }

    private function relationManager()
    {
        return Livewire::test(MembersRelationManager::class, [
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
}
