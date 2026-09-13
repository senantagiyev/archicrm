<?php

namespace Tests\Support;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentType;
use App\Enums\FileVisibility;
use App\Models\Approval;
use App\Models\BudgetLine;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Payment;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

/**
 * A complete, realistic studio (tenant) for scenario QA: the full staff cast,
 * two clients with portal accounts, two projects, and one of every record the
 * access matrix and the portal care about.
 *
 * Two worlds built side by side model the "different studio" scenarios; inside
 * one world, `project` vs `otherProject` models the "same studio, different
 * project" scenarios (membership scoping).
 */
class StudioWorld
{
    public Tenant $tenant;

    /** @var array<string, User> Staff keyed by StaffRole value. */
    public array $staff = [];

    public Client $client;

    public Client $secondClient;

    public ClientUser $portalUser;

    public ClientUser $secondPortalUser;

    /** Project the PM manages; designer and procurement are members. */
    public Project $project;

    /** Second project of the SAME studio, different client, no shared members. */
    public Project $otherProject;

    public Stage $stage;

    public Task $task;

    public BudgetLine $budgetLine;

    public ProcurementItem $procurementItem;

    public Payment $payment;

    public Document $clientDocument;

    public Document $internalDocument;

    public ProjectFile $internalFile;

    public ProjectFile $sharedFile;

    public Approval $approval;

    public ChatMessage $chatMessage;

    public static function make(string $slug): self
    {
        $world = new self;

        $world->tenant = Tenant::create([
            'name' => ucfirst($slug).' Studio',
            'slug' => $slug,
            'active' => true,
        ]);

        app(TenantContext::class)->actingAs($world->tenant->id, fn () => $world->build($slug));

        return $world;
    }

    public function user(string $role): User
    {
        return $this->staff[$role];
    }

    private function build(string $slug): void
    {
        foreach (['owner', 'project_manager', 'designer', 'visualizer', 'procurement', 'accountant'] as $role) {
            $this->staff[$role] = User::create([
                'name' => ucfirst($slug).' '.$role,
                'email' => $role.'@'.$slug.'.test',
                'password' => 'secret123',
                'role' => $role,
                'is_active' => true,
            ]);
        }

        $this->client = Client::create(['name' => ucfirst($slug).' müştəri 1', 'status' => 'client']);
        $this->secondClient = Client::create(['name' => ucfirst($slug).' müştəri 2', 'status' => 'client']);

        $this->portalUser = ClientUser::create([
            'client_id' => $this->client->id,
            'name' => ucfirst($slug).' portal 1',
            'email' => 'portal1@'.$slug.'.test',
        ]);

        $this->secondPortalUser = ClientUser::create([
            'client_id' => $this->secondClient->id,
            'name' => ucfirst($slug).' portal 2',
            'email' => 'portal2@'.$slug.'.test',
        ]);

        $this->project = Project::create([
            'client_id' => $this->client->id,
            'name' => ucfirst($slug).' əsas layihə',
            'type' => 'apartment',
            'status' => 'active',
            'manager_user_id' => $this->staff['project_manager']->id,
        ]);

        $this->project->members()->attach($this->staff['project_manager']->id, ['project_role' => 'manager']);
        $this->project->members()->attach($this->staff['designer']->id, ['project_role' => 'designer']);
        $this->project->members()->attach($this->staff['procurement']->id, ['project_role' => 'procurement']);

        // Same studio, different client, and deliberately NO shared members:
        // this is what the "own projects only" roles must be blocked from.
        $this->otherProject = Project::create([
            'client_id' => $this->secondClient->id,
            'name' => ucfirst($slug).' ikinci layihə',
            'type' => 'house',
            'status' => 'active',
            'manager_user_id' => $this->staff['owner']->id,
        ]);

        $this->stage = $this->project->stages()->create([
            'name' => 'Eskiz', 'position' => 1, 'weight' => 1, 'status' => 'in_progress',
        ]);

        $this->task = Task::create([
            'project_id' => $this->project->id,
            'stage_id' => $this->stage->id,
            'title' => 'Planlaşdırma',
            'status' => 'todo',
            'assignee_user_id' => $this->staff['designer']->id,
        ]);

        $this->budgetLine = $this->project->budgetLines()->create([
            'work_type' => 'Divar', 'unit' => 'm2', 'qty' => 10,
            'work_price' => 50, 'material_price' => 20, 'position' => 1,
            'visible_to_client' => true,
        ]);

        $this->procurementItem = $this->project->procurementItems()->create([
            'name' => 'Divan', 'qty' => 1, 'price' => 1200, 'purchase_status' => 'planned',
        ]);

        $this->payment = $this->project->payments()->create([
            'title' => 'Avans', 'amount' => 500, 'status' => 'pending', 'due_date' => now()->addWeek(),
        ]);

        $this->clientDocument = Document::create([
            'project_id' => $this->project->id,
            'type' => DocumentType::Contract->value,
            'title' => 'Müqavilə',
            'file_path' => 'docs/'.$slug.'-contract.pdf',
            'visible_to_client' => true,
        ]);

        $this->internalDocument = Document::create([
            'project_id' => $this->project->id,
            'type' => DocumentType::Other->value,
            'title' => 'Daxili qeyd',
            'file_path' => 'docs/'.$slug.'-internal.pdf',
            'visible_to_client' => false,
        ]);

        $this->internalFile = ProjectFile::create([
            'project_id' => $this->project->id,
            'category' => 'plan',
            'visibility' => FileVisibility::Internal->value,
            'title' => 'Daxili cizgi',
            'file_path' => 'files/'.$slug.'-internal.dwg',
        ]);

        $this->sharedFile = ProjectFile::create([
            'project_id' => $this->project->id,
            'category' => 'plan',
            'visibility' => FileVisibility::ClientShared->value,
            'title' => 'Paylaşılan cizgi',
            'file_path' => 'files/'.$slug.'-shared.pdf',
        ]);

        $this->approval = Approval::create([
            'approvable_type' => 'budget_line',
            'approvable_id' => $this->budgetLine->id,
            'project_id' => $this->project->id,
            'requested_by_user_id' => $this->staff['project_manager']->id,
            'client_user_id' => $this->portalUser->id,
            'status' => ApprovalStatus::Pending->value,
        ]);

        $this->chatMessage = ChatMessage::create([
            'project_id' => $this->project->id,
            'author_type' => 'user',
            'author_id' => $this->staff['project_manager']->id,
            'body' => 'Salam, eskizlər hazırdır.',
        ]);
    }
}
