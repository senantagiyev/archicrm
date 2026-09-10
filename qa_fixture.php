<?php

/**
 * QA fixture: two isolated studios, each with staff, a client portal user,
 * a project, stages and tasks. Idempotent — safe to re-run.
 */

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Project;
use App\Models\Stage;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

// Notifications are not queued, so seeding tasks would otherwise hit real SMTP.
config(['mail.default' => 'log']);

$ctx = app(TenantContext::class);

$build = function (string $slug, string $name, string $projectName) use ($ctx) {
    $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => $name, 'active' => true]);

    return $ctx->actingAs($tenant->id, function () use ($tenant, $slug, $projectName) {
        $owner = User::withoutGlobalScope('tenant')->firstOrNew(['email' => "owner@{$slug}.test"]);
        $owner->forceFill([
            'tenant_id' => $tenant->id, 'name' => ucfirst($slug).' Sahibkar',
            'password' => 'secret123', 'role' => 'owner', 'is_active' => true,
        ])->save();

        $designer = User::withoutGlobalScope('tenant')->firstOrNew(['email' => "designer@{$slug}.test"]);
        $designer->forceFill([
            'tenant_id' => $tenant->id, 'name' => ucfirst($slug).' Dizayner',
            'password' => 'secret123', 'role' => 'designer', 'is_active' => true,
        ])->save();

        $accountant = User::withoutGlobalScope('tenant')->firstOrNew(['email' => "accountant@{$slug}.test"]);
        $accountant->forceFill([
            'tenant_id' => $tenant->id, 'name' => ucfirst($slug).' Mühasib',
            'password' => 'secret123', 'role' => 'accountant', 'is_active' => true,
        ])->save();

        // A designer in the same studio who is NOT on the project — for own-project checks.
        $outsider = User::withoutGlobalScope('tenant')->firstOrNew(['email' => "outsider@{$slug}.test"]);
        $outsider->forceFill([
            'tenant_id' => $tenant->id, 'name' => ucfirst($slug).' Kənar Dizayner',
            'password' => 'secret123', 'role' => 'designer', 'is_active' => true,
        ])->save();

        $client = Client::firstOrCreate(
            ['name' => ucfirst($slug).' Müştəri'],
            ['status' => 'client', 'responsible_user_id' => $owner->id],
        );

        $clientUser = ClientUser::firstOrCreate(
            ['email' => "client@{$slug}.test"],
            ['client_id' => $client->id, 'name' => ucfirst($slug).' Sifarişçi'],
        );

        $project = Project::firstOrCreate(
            ['name' => $projectName],
            [
                'client_id' => $client->id, 'type' => 'apartment', 'status' => 'active',
                'manager_user_id' => $owner->id,
            ],
        );

        $project->members()->syncWithoutDetaching([$designer->id => ['project_role' => 'designer']]);

        $stage = Stage::firstOrCreate(
            ['project_id' => $project->id, 'name' => 'Konsepsiya'],
            ['status' => 'in_progress', 'position' => 1],
        );

        foreach ([
            ['Planlaşdırma eskizi', $designer->id],
            ['Moodboard hazırla', $designer->id],
            ['Smetanı yoxla', $accountant->id],
        ] as [$title, $assignee]) {
            Task::firstOrCreate(
                ['project_id' => $project->id, 'title' => $title],
                [
                    'stage_id' => $stage->id, 'assignee_user_id' => $assignee,
                    'author_user_id' => $owner->id, 'status' => 'todo',
                    'priority' => 'normal', 'deadline' => now()->addDays(7),
                ],
            );
        }

        return compact('tenant', 'owner', 'designer', 'accountant', 'outsider', 'client', 'clientUser', 'project', 'stage');
    });
};

$a = $build('alfa', 'Studio Alfa', 'Alfa — Nizami mənzili');
$b = $build('beta', 'Studio Beta', 'Beta — Xətai villası');

foreach (['A' => $a, 'B' => $b] as $letter => $s) {
    echo "TENANT_{$letter}={$s['tenant']->id} ({$s['tenant']->slug})\n";
    echo "  owner={$s['owner']->id} designer={$s['designer']->id} accountant={$s['accountant']->id} outsider={$s['outsider']->id}\n";
    echo "  client={$s['client']->id} clientUser={$s['clientUser']->id} project={$s['project']->id}\n";
    echo '  tasks='.implode(',', Task::withoutGlobalScope('tenant')->where('project_id', $s['project']->id)->pluck('id')->all())."\n";
}
