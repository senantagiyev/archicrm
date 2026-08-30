<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Enums\ClientStatus;
use App\Enums\ProjectRole;
use App\Filament\Resources\ProjectResource;
use App\Models\Client;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    /** Prefill the client when arriving from a client card ("Layihə yarat"). */
    protected function afterFill(): void
    {
        if ($clientId = request()->integer('client')) {
            $this->data['client_id'] = $clientId;
        }
    }

    protected function afterCreate(): void
    {
        $project = $this->record;

        // The creator's manager becomes a member automatically.
        if ($project->manager_user_id) {
            $project->members()->syncWithoutDetaching([
                $project->manager_user_id => ['project_role' => ProjectRole::Manager->value],
            ]);
        }

        // TZ §5.12: a lead is converted to a client when its first project is created.
        $client = Client::find($project->client_id);

        if ($client && in_array($client->status, [ClientStatus::Lead, ClientStatus::Negotiation], true)) {
            $client->update(['status' => ClientStatus::Client]);

            $client->contactLogs()->create([
                'user_id' => auth()->id(),
                'type' => 'note',
                'note' => "Lid layihəyə çevrildi: {$project->name}",
                'contacted_at' => now(),
            ]);
        }
    }
}
