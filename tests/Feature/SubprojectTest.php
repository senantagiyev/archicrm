<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Roomix «Subprojects»: layihənin təmir mərhələsi üçün ayrıca məkan.
 *
 * Alt-layihə də tam hüquqlu layihədir, ona görə əsas risk SCOPE-dadır:
 * valideyn müştərinin olsa da, alt-layihə səhvən başqa müştəriyə bağlansa,
 * portal onu göstərərdi.
 */
class SubprojectTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('subproject');
    }

    private function makeSubproject(): Project
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => Project::create([
            'client_id' => $this->studio->project->client_id,
            'parent_project_id' => $this->studio->project->id,
            'name' => 'Təmir',
            'type' => $this->studio->project->type,
            'manager_user_id' => $this->studio->project->manager_user_id,
        ]));
    }

    public function test_a_subproject_knows_its_parent_and_the_parent_lists_it(): void
    {
        $child = $this->makeSubproject();

        $this->assertTrue($child->isSubproject());
        $this->assertFalse($this->studio->project->fresh()->isSubproject());
        $this->assertSame($this->studio->project->id, $child->parent->id);
        $this->assertTrue($this->studio->project->subprojects()->get()->contains('id', $child->id));
    }

    /** Alt-layihə müştərinin öz siyahısındadır — ayrıca dəvət lazım deyil. */
    public function test_the_customer_sees_the_subproject_marked_under_its_parent(): void
    {
        $child = $this->makeSubproject();

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.home'));

        $response->assertOk();
        $response->assertSee($child->name);
        $response->assertSee($this->studio->project->name);
    }

    public function test_a_subproject_opens_its_own_project_page(): void
    {
        $child = $this->makeSubproject();

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $child))
            ->assertOk()
            ->assertSee($child->name);
    }

    /** Başqa müştərinin alt-layihəsi görünməməlidir. */
    public function test_another_clients_subproject_stays_out_of_reach(): void
    {
        $foreign = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => Project::create([
            'client_id' => $this->studio->otherProject->client_id,
            'parent_project_id' => $this->studio->otherProject->id,
            'name' => 'Yad təmir',
            'type' => $this->studio->otherProject->type,
            'manager_user_id' => $this->studio->otherProject->manager_user_id,
        ]));

        // Müştərinin tək layihəsi var, ona görə siyahı birbaşa ona yönləndirir —
        // yönləndirmədən sonrakı səhifədə də yad ad görünməməlidir.
        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.home'))
            ->assertRedirect(route('portal.projects.show', $this->studio->project));

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $this->studio->project))
            ->assertOk()
            ->assertDontSee('Yad təmir');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $foreign))
            ->assertNotFound();
    }

    /**
     * Valideyn silinsə alt-layihə öz məlumatı ilə qalmalıdır — kaskad silmə
     * müştərinin çatını və sənədlərini də aparardı.
     *
     * `Project` soft-delete etdiyinə görə sətir yerində qalır və `parent_project_id`
     * dəyişmir; `parent` münasibəti isə silinmiş valideyni qaytarmır. Portal
     * kartı məhz buna görə `$project->parent` yoxlamasından keçir.
     */
    public function test_the_subproject_survives_its_parents_deletion(): void
    {
        $child = $this->makeSubproject();

        app(TenantContext::class)->actingAs($this->studio->tenant->id, function () {
            $this->studio->project->delete();
        });

        $child->refresh();

        $this->assertNotNull($child, 'Alt-layihə valideynlə birlikdə silinməməlidir.');
        $this->assertNull($child->parent, 'Silinmiş valideyn münasibətdən çıxmalıdır.');

        $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.projects.show', $child))
            ->assertOk();
    }
}
