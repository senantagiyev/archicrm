<?php

namespace Tests\Feature\Fix;

use App\Enums\ClientSource;
use App\Enums\LeadStatus;
use App\Enums\ProjectStatus;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\MeetingResource\Pages\CreateMeeting;
use App\Filament\Resources\MeetingResource\Pages\ListMeetings;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA tapıntılarının düzəlişlərinin reqressiya testləri: Lead konversiyası,
 * Client silmə təsirləri, Meeting forması/modeli.
 *
 * Hər test metodunun şərhində hansı tapıntını qoruduğu göstərilib.
 */
class CrmModulesFixTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alfa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = StudioWorld::make('alpha');
    }

    /** Filament səhifəsini konkret işçi kimi aç. */
    private function asStaff(string $role): User
    {
        $user = $this->alfa->user($role);
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->alfa->tenant->id);
        AccessMatrix::flushCache();

        return $user;
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($this->alfa->tenant->id, $callback);
    }

    // =====================================================================
    // LEAD 1 [CİDDİ] — konversiya idempotentdir, iz saxlanılır.
    // =====================================================================

    public function test_lead_conversion_is_idempotent_and_keeps_the_client_link(): void
    {
        $this->assertTrue(Schema::hasColumn('leads', 'client_id'), 'Lid → müştəri izi sütunu yoxdur.');

        $lead = $this->inTenant(fn () => Lead::create([
            'first_name' => 'Nigar', 'last_name' => 'Həsənova',
            'phone' => '+994501234567', 'status' => LeadStatus::Negotiation->value,
        ]));

        $first = $this->inTenant(fn () => LeadResource::convertToClient($lead));

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertSame($first->id, $lead->fresh()->client_id, 'Konversiya izi yazılmır.');
        $this->assertSame(LeadStatus::Won, $lead->fresh()->status);

        // Operator statusu geri çevirir — düymə yenidən görünür.
        $lead->update(['status' => LeadStatus::Negotiation->value]);
        $second = $this->inTenant(fn () => LeadResource::convertToClient($lead->fresh()));

        $this->assertSame($first->id, $second->id, 'İkinci konversiya yeni müştəri yaratdı.');
        $this->assertFalse($second->wasRecentlyCreated, 'Mövcud müştəri "yeni yaradıldı" kimi qaytarılır.');
        $this->assertSame(1, $this->inTenant(
            fn () => Client::where('name', 'Nigar Həsənova')->count()
        ), 'Dublikat müştəri yarandı.');
        // Status yenidən "Qazanılıb" olur.
        $this->assertSame(LeadStatus::Won, $lead->fresh()->status);
    }

    /** Bağlı müştəri silinibsə lid dalanda qalmır: yeni müştəri yaranır və iz yenilənir. */
    public function test_lead_can_be_reconverted_when_the_linked_client_was_deleted(): void
    {
        $lead = $this->inTenant(fn () => Lead::create([
            'first_name' => 'Kamran', 'status' => LeadStatus::Negotiation->value,
        ]));

        $first = $this->inTenant(fn () => LeadResource::convertToClient($lead));
        // Layihəsi olmayan müştəri silinə bilir.
        $this->inTenant(fn () => Client::find($first->id)->delete());

        $second = $this->inTenant(fn () => LeadResource::convertToClient($lead->fresh()));

        $this->assertTrue($second->wasRecentlyCreated);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($second->id, $lead->fresh()->client_id);
    }

    // =====================================================================
    // LEAD 2 [ORTA] — tanınmayan mənbə itmir.
    // =====================================================================

    public function test_unknown_lead_source_falls_back_to_other_and_keeps_the_original_text(): void
    {
        $lead = $this->inTenant(fn () => Lead::create([
            'first_name' => 'Kamran', 'lead_source' => 'tiktok-reklam',
            'status' => LeadStatus::New->value, 'notes' => 'Qeyd mətni',
        ]));

        $client = $this->inTenant(fn () => LeadResource::convertToClient($lead))->fresh();

        $this->assertSame(ClientSource::Other, $client->source, 'Tanınmayan mənbə yenə null-a düşür.');
        $this->assertStringContainsString('tiktok-reklam', $client->notes, 'Orijinal mənbə mətni itdi.');
        // Mövcud qeyd silinmir.
        $this->assertStringContainsString('Qeyd mətni', $client->notes);
    }

    /** Etibarlı mənbə dəyişdirilmir, qeydə də əlavə yazılmır. */
    public function test_known_lead_source_is_mapped_exactly(): void
    {
        $lead = $this->inTenant(fn () => Lead::create([
            'first_name' => 'Rəşad', 'lead_source' => 'instagram',
            'status' => LeadStatus::New->value, 'notes' => 'Qeyd',
        ]));

        $client = $this->inTenant(fn () => LeadResource::convertToClient($lead))->fresh();

        $this->assertSame(ClientSource::Instagram, $client->source);
        $this->assertSame('Qeyd', $client->notes);
    }

    /** Mənbə boş olanda köhnə davranış qalır: null, qeydə əlavə yoxdur. */
    public function test_empty_lead_source_stays_null(): void
    {
        $lead = $this->inTenant(fn () => Lead::create([
            'first_name' => 'Aygün', 'status' => LeadStatus::New->value,
        ]));

        $client = $this->inTenant(fn () => LeadResource::convertToClient($lead))->fresh();

        $this->assertNull($client->source);
        $this->assertNull($client->notes);
    }

    // =====================================================================
    // CLIENT 3 [CİDDİ] — aktiv layihəsi olan müştəri silinmir.
    // =====================================================================

    public function test_client_with_a_live_project_cannot_be_deleted(): void
    {
        $clientId = $this->alfa->client->id;
        $projectId = $this->alfa->project->id;

        try {
            $this->inTenant(fn () => Client::find($clientId)->delete());
            $this->fail('Aktiv layihəsi olan müştəri silindi — guard işləmir.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('layihə', $e->getMessage());
        }

        $this->assertNotNull($this->inTenant(fn () => Client::find($clientId)), 'Müştəri silindi.');
        $this->assertNotNull($this->inTenant(fn () => Project::find($projectId)));
        $this->assertDatabaseHas('clients', ['id' => $clientId, 'deleted_at' => null]);
    }

    /** Qaralama və dayandırılmış layihə də «tamamlanmamış»dır — silmə bloklanır. */
    public function test_draft_and_on_hold_projects_also_block_client_deletion(): void
    {
        foreach ([ProjectStatus::Draft, ProjectStatus::OnHold] as $status) {
            $this->inTenant(fn () => Project::find($this->alfa->project->id)->update(['status' => $status->value]));

            $blocked = false;
            try {
                $this->inTenant(fn () => Client::find($this->alfa->client->id)->delete());
            } catch (\RuntimeException) {
                $blocked = true;
            }

            $this->assertTrue($blocked, "«{$status->value}» statuslu layihə silməni bloklamadı.");
        }

        $this->assertDatabaseHas('clients', ['id' => $this->alfa->client->id, 'deleted_at' => null]);
    }

    /** Tamamlanmış/arxiv layihə tarixçədir — müştəri silinə bilir. */
    public function test_client_with_only_finished_projects_can_be_deleted(): void
    {
        $this->inTenant(fn () => Project::find($this->alfa->project->id)
            ->update(['status' => ProjectStatus::Archived->value]));

        $this->inTenant(fn () => Client::find($this->alfa->client->id)->delete());

        $this->assertSoftDeleted('clients', ['id' => $this->alfa->client->id]);
    }

    // =====================================================================
    // CLIENT 4 [ORTA] — müştəri silinəndə portal girişi bağlanır.
    // =====================================================================

    public function test_client_delete_closes_its_portal_accounts(): void
    {
        $portalUserId = $this->alfa->portalUser->id;

        // Silmə mümkün olsun deyə layihə arxivləşdirilir.
        $this->inTenant(fn () => Project::find($this->alfa->project->id)
            ->update(['status' => ProjectStatus::Archived->value]));

        $this->inTenant(fn () => Client::find($this->alfa->client->id)->delete());

        $this->assertSoftDeleted('client_users', ['id' => $portalUserId]);
        $this->assertNull($this->inTenant(fn () => ClientUser::find($portalUserId)));
        // Başqa müştərinin portal hesabı toxunulmur.
        $this->assertDatabaseHas('client_users', [
            'id' => $this->alfa->secondPortalUser->id, 'deleted_at' => null,
        ]);
    }

    // =====================================================================
    // MEETING 5 [CİDDİ] — iştirakçılar və protokol formada var.
    // =====================================================================

    public function test_meeting_participants_and_protocol_are_editable_from_admin_ui(): void
    {
        $owner = $this->asStaff('owner');
        $designer = $this->alfa->user('designer');

        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'title' => 'Eskiz təqdimatı',
                'starts_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->setTime(11, 0)->format('Y-m-d H:i:s'),
                'participants' => [$owner->id, $designer->id],
                'recording_link' => 'https://rec.test/protokol-1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $meeting = $this->inTenant(fn () => Meeting::where('title', 'Eskiz təqdimatı')->first());

        $this->assertNotNull($meeting);
        $this->assertSame([$owner->id, $designer->id], array_map('intval', $meeting->participants));
        $this->assertSame('https://rec.test/protokol-1', $meeting->recording_link);

        // Cədvəldə id yox, ad göstərilir.
        $names = $this->inTenant(fn () => $meeting->participantNames());
        $this->assertContains($owner->name, $names);
        $this->assertContains($designer->name, $names);
    }

    /** Cədvəl sütunu id-ləri deyil, adları bir sətirdə göstərir. */
    public function test_meeting_table_shows_participant_names(): void
    {
        $owner = $this->asStaff('owner');
        $designer = $this->alfa->user('designer');

        $this->inTenant(fn () => Meeting::create([
            'project_id' => $this->alfa->project->id, 'title' => 'Sütun testi',
            'starts_at' => now()->addDay(), 'participants' => [$owner->id, $designer->id],
        ]));

        Livewire::test(ListMeetings::class)
            ->assertOk()
            ->assertSee($owner->name.', '.$designer->name);
    }

    /** Köhnə qeydlərdəki sərbəst mətn iştirakçılar da oxunur (geriyə uyğunluq). */
    public function test_legacy_text_participants_are_still_readable(): void
    {
        $meeting = $this->inTenant(fn () => Meeting::create([
            'project_id' => $this->alfa->project->id, 'title' => 'Köhnə görüş',
            'starts_at' => now()->addDay(), 'participants' => ['Aygün', 'Rəşad'],
        ]));

        $this->assertSame(['Aygün', 'Rəşad'], $this->inTenant(fn () => $meeting->participantNames()));
    }

    /** Protokol keçidi URL olmalıdır. */
    public function test_protocol_link_must_be_a_url(): void
    {
        $this->asStaff('owner');

        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'title' => 'Protokolsuz',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'recording_link' => 'protokol-faylı',
            ])
            ->call('create')
            ->assertHasFormErrors(['recording_link']);
    }

    // =====================================================================
    // MEETING 6 [CİDDİ] — forma dropdown-u rola görə daralır.
    // =====================================================================

    public function test_scoped_role_cannot_create_meeting_on_a_project_it_cannot_see(): void
    {
        $designer = $this->asStaff('designer');
        $this->assertTrue(AccessMatrix::requiresOwnProject($designer));
        $this->assertFalse($this->alfa->otherProject->hasMember($designer));

        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->alfa->otherProject->id,
                'title' => 'Yad layihədə görüş',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasFormErrors(['project_id']);

        $this->assertNull(
            $this->inTenant(fn () => Meeting::where('title', 'Yad layihədə görüş')->first()),
            'Yad layihəyə görüş yarandı.'
        );
    }

    /** Öz layihəsinə görüş yaratmaq isə işləyir — scope həddindən artıq daraltmır. */
    public function test_scoped_role_can_still_create_meeting_on_its_own_project(): void
    {
        $this->asStaff('designer');

        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'title' => 'Öz layihəsində görüş',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull($this->inTenant(fn () => Meeting::where('title', 'Öz layihəsində görüş')->first()));
    }

    /** Owner (scope-suz rol) bütün layihələri görür. */
    public function test_unscoped_role_sees_every_project_in_the_dropdown(): void
    {
        $this->asStaff('owner');

        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->alfa->otherProject->id,
                'title' => 'Owner görüşü',
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    // =====================================================================
    // MEETING 7 [ORTA] — bitmə vaxtı başlanğıcdan əvvəl ola bilməz.
    // =====================================================================

    public function test_form_rejects_end_before_start(): void
    {
        $this->asStaff('owner');

        Livewire::test(CreateMeeting::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'title' => 'Tərs görüş',
                'starts_at' => now()->addDay()->setTime(15, 0)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->setTime(9, 0)->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasFormErrors(['ends_at']);

        $this->assertNull($this->inTenant(fn () => Meeting::where('title', 'Tərs görüş')->first()));
    }

    public function test_model_rejects_end_before_start(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->inTenant(fn () => Meeting::create([
            'project_id' => $this->alfa->project->id,
            'title' => 'Proqramla tərs görüş',
            'starts_at' => now()->addDay()->setTime(15, 0),
            'ends_at' => now()->addDay()->setTime(9, 0),
        ]));
    }

    /** Normal görüş (və bitmə vaxtı boş olan görüş) problemsiz saxlanılır. */
    public function test_valid_meeting_durations_are_accepted(): void
    {
        $withEnd = $this->inTenant(fn () => Meeting::create([
            'project_id' => $this->alfa->project->id, 'title' => 'Normal',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 30),
        ]));
        $withoutEnd = $this->inTenant(fn () => Meeting::create([
            'project_id' => $this->alfa->project->id, 'title' => 'Bitmə vaxtı yox',
            'starts_at' => now()->addDay()->setTime(12, 0),
        ]));

        $this->assertNotNull($withEnd->fresh());
        $this->assertNull($withoutEnd->fresh()->ends_at);
    }
}
