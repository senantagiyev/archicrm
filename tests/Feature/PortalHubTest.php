<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentType;
use App\Models\Approval;
use App\Models\Document;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * The global left-nav pages (approvals / documents / profile) list records
 * across ALL of the customer's projects. That is exactly where a scoping bug
 * leaks another client's money, so every listing is tested against a sibling
 * client inside the SAME studio — the case a tenant scope alone would miss.
 */
class PortalHubTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private Approval $foreignApproval;

    private Document $foreignDocument;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studio = StudioWorld::make('hub');

        // Same studio, different client: `otherProject` belongs to secondClient.
        app(TenantContext::class)->actingAs($this->studio->tenant->id, function (): void {
            $foreignLine = $this->studio->otherProject->budgetLines()->create([
                'work_type' => 'Yad divar', 'unit' => 'm2', 'qty' => 5,
                'work_price' => 100, 'material_price' => 0, 'position' => 1,
                'visible_to_client' => true,
            ]);

            $this->foreignApproval = Approval::create([
                'approvable_type' => 'budget_line',
                'approvable_id' => $foreignLine->id,
                'project_id' => $this->studio->otherProject->id,
                'requested_by_user_id' => $this->studio->user('owner')->id,
                'client_user_id' => $this->studio->secondPortalUser->id,
                'status' => ApprovalStatus::Pending->value,
            ]);

            $this->foreignDocument = Document::create([
                'project_id' => $this->studio->otherProject->id,
                'type' => DocumentType::Contract->value,
                'title' => 'Yad müqavilə',
                'file_path' => 'docs/hub-foreign.pdf',
                'visible_to_client' => true,
            ]);
        });
    }

    public function test_the_global_approvals_page_lists_only_the_customers_own_pending_items(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.approvals.all'));

        $response->assertOk();
        // Own pending approval, shown with its project name.
        $response->assertSee($this->studio->project->name);
        $response->assertSee($this->studio->budgetLine->work_type);

        // Another client of the same studio must not appear at all.
        $response->assertDontSee($this->studio->otherProject->name);
        $response->assertDontSee('Yad divar');
    }

    public function test_the_global_documents_page_hides_other_clients_and_internal_files(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.documents.all'));

        $response->assertOk();
        $response->assertSee($this->studio->clientDocument->title);
        $response->assertSee($this->studio->project->name);

        $response->assertDontSee($this->studio->internalDocument->title);
        $response->assertDontSee('Yad müqavilə');
    }

    public function test_a_customer_cannot_reach_another_clients_approval_through_the_hub_decision_route(): void
    {
        $this->actingAs($this->studio->portalUser, 'customer')
            ->post(route('portal.approvals.decide', $this->foreignApproval), [
                'decision' => 'approve',
            ])
            ->assertNotFound();

        $this->assertSame(
            ApprovalStatus::Pending,
            $this->foreignApproval->refresh()->status,
            'Yad müştərinin razılaşdırması portaldan təsdiqləndi.',
        );
    }

    public function test_the_profile_page_shows_the_account_and_never_a_password_form(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.profile'));

        $response->assertOk();
        $response->assertSee($this->studio->portalUser->name);
        $response->assertSee($this->studio->portalUser->email);
        // Passwordless portal: a password input here would be a dead control.
        $response->assertDontSee('type="password"', false);
    }

    public function test_the_hub_pages_are_closed_to_guests(): void
    {
        foreach (['portal.approvals.all', 'portal.documents.all', 'portal.profile'] as $name) {
            $this->get(route($name))->assertRedirect(route('portal.login'));
        }
    }

    public function test_the_left_nav_links_to_every_global_page(): void
    {
        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.approvals.all'));

        $response->assertSee(route('portal.home'), false);
        $response->assertSee(route('portal.approvals.all'), false);
        $response->assertSee(route('portal.documents.all'), false);
        $response->assertSee(route('portal.profile'), false);
    }
}
