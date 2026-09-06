<?php

namespace Tests\Feature;

use App\Enums\ClientStatus;
use App\Enums\LeadStatus;
use App\Filament\Resources\LeadResource;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_can_be_created_with_defaults(): void
    {
        $lead = Lead::create([
            'first_name' => 'Aygün',
            'last_name' => 'Məmmədova',
            'phone' => '+994501112233',
            'status' => LeadStatus::New->value,
        ]);

        $this->assertDatabaseHas('leads', ['first_name' => 'Aygün', 'status' => 'new']);
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
        $this->assertSame('Aygün Məmmədova', $lead->full_name);
    }

    public function test_convert_to_client_creates_client_and_marks_lead_won(): void
    {
        $manager = User::create([
            'name' => 'Menecer', 'email' => 'm@test.az', 'password' => 'secret123',
            'role' => 'project_manager',
        ]);

        $lead = Lead::create([
            'first_name' => 'Rəşad',
            'last_name' => 'Əliyev',
            'company' => 'Əliyev MMC',
            'phone' => '+994557778899',
            'email' => 'resad@test.az',
            'whatsapp' => '+994557778899',
            'telegram' => '@resad',
            'lead_source' => 'instagram',
            'responsible_user_id' => $manager->id,
            'status' => LeadStatus::Negotiation->value,
            'notes' => 'Maraqlı lid',
        ]);

        $client = LeadResource::convertToClient($lead);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertDatabaseHas('clients', [
            'name' => 'Rəşad Əliyev',
            'company' => 'Əliyev MMC',
            'phone' => '+994557778899',
            'email' => 'resad@test.az',
            'whatsapp' => '+994557778899',
            'telegram' => '@resad',
            'responsible_user_id' => $manager->id,
        ]);

        $client = $client->fresh();
        $this->assertSame(ClientStatus::Client, $client->status);
        // Etibarlı ClientSource dəyəri ötürülür.
        $this->assertSame('instagram', $client->source->value);

        // Lid "Qazanılıb" olaraq işarələnir.
        $this->assertSame(LeadStatus::Won, $lead->fresh()->status);
    }

    public function test_convert_to_client_ignores_invalid_lead_source(): void
    {
        $lead = Lead::create([
            'first_name' => 'Nigar',
            'lead_source' => 'sərbəst mətn mənbə',
            'status' => LeadStatus::New->value,
        ]);

        $client = LeadResource::convertToClient($lead)->fresh();

        // Etibarsız mənbə enum cast-ı pozmamaq üçün null saxlanılır.
        $this->assertNull($client->source);
        $this->assertSame('Nigar', $client->name);
    }
}
