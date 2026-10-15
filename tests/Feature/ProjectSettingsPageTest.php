<?php

namespace Tests\Feature;

use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ProjectSettings;
use App\Filament\Resources\ProjectResource\RelationManagers\MembersRelationManager;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * «Layihə parametrləri» səhifəsi (TZ «Layihə naviqasiyası» §2, meyar 2):
 * layihənin forması + komanda tərkibi, layihə kartından ayrı.
 */
class ProjectSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('settingspage');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_the_settings_page_shows_the_form_and_the_team(): void
    {
        $this->asStaff('owner');

        $this->get(ProjectResource::getUrl('settings', ['record' => $this->studio->project]))
            ->assertOk()
            ->assertSee('Layihə parametrləri')
            ->assertSee('Layihənin adı')
            ->assertSee('Müştərinin cavab müddəti')
            ->assertSee('Komanda')
            ->assertSee($this->studio->user('designer')->name);

        Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $this->studio->project,
            'pageClass' => ProjectSettings::class,
        ])->assertCanSeeTableRecords([
            $this->studio->user('project_manager'),
            $this->studio->user('designer'),
            $this->studio->user('procurement'),
        ]);
    }

    public function test_saving_stays_on_the_settings_page(): void
    {
        $this->asStaff('project_manager');

        Livewire::test(ProjectSettings::class, ['record' => $this->studio->project->getKey()])
            ->assertActionExists('delete')
            ->fillForm(['name' => 'Yeni ad', 'client_response_days' => 7])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Layihə parametrləri yadda saxlanıldı')
            ->assertRedirect(ProjectResource::getUrl('settings', ['record' => $this->studio->project]));

        $project = $this->studio->project->fresh();
        $this->assertSame('Yeni ad', $project->name);
        $this->assertSame(7, (int) $project->client_response_days);
    }

    public function test_a_role_without_project_edit_rights_gets_403(): void
    {
        // Mühasib: Layihələr = Bax — kartı redaktə edə bilmir, parametrləri də.
        $this->asStaff('accountant');
        $this->get(ProjectResource::getUrl('settings', ['record' => $this->studio->project]))->assertForbidden();

        // Üzvü olmadığı layihə — «öz layihələri» rolu.
        $this->asStaff('designer');
        $status = $this->get(ProjectResource::getUrl('settings', ['record' => $this->studio->otherProject]))->status();
        $this->assertContains($status, [403, 404]);

        $this->get(ProjectResource::getUrl('settings', ['record' => $this->studio->project]))->assertOk();
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
