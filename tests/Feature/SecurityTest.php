<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Models\Client;
use App\Models\Payment;
use App\Models\ProcurementItem;
use App\Models\Project;
use App\Models\User;
use App\Rules\SafeUpload;
use App\Services\Portal\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $role = 'owner'): array
    {
        $user = User::create(['name' => 'U', 'email' => 'u@t.az', 'password' => 'secret123', 'role' => $role]);
        $client = Client::create(['name' => 'C', 'status' => 'client']);
        $project = Project::create([
            'client_id' => $client->id, 'name' => 'P', 'type' => 'apartment',
            'status' => 'active', 'manager_user_id' => $user->id,
        ]);

        return [$user, $client, $project];
    }

    /** HIGH-1: a Visualizer has no rights on payments/budget/procurement. */
    public function test_visualizer_denied_on_finance_records(): void
    {
        [, , $project] = $this->project('owner');
        $viz = User::create(['name' => 'V', 'email' => 'v@t.az', 'password' => 'secret123', 'role' => 'visualizer']);

        $payment = $project->payments()->create(['title' => 'A', 'amount' => 100, 'status' => 'pending']);
        $line = $project->budgetLines()->create(['work_type' => 'W', 'qty' => 1, 'work_price' => 10]);

        $this->assertFalse($viz->can('viewAny', Payment::class));
        $this->assertFalse($viz->can('update', $payment));
        $this->assertFalse($viz->can('update', $line));
    }

    /** Accountant owns payments but cannot touch procurement beyond view. */
    public function test_accountant_matrix_boundaries(): void
    {
        [, , $project] = $this->project('owner');
        $acc = User::create(['name' => 'A', 'email' => 'a@t.az', 'password' => 'secret123', 'role' => 'accountant']);

        $payment = $project->payments()->create(['title' => 'A', 'amount' => 100, 'status' => 'pending']);
        $item = $project->procurementItems()->create(['name' => 'X', 'price' => 10, 'qty' => 1]);

        $this->assertTrue($acc->can('update', $payment));
        $this->assertTrue($acc->can('viewAny', ProcurementItem::class));
        $this->assertFalse($acc->can('update', $item));
    }

    /** HIGH-2: approval_status is not mass-assignable. */
    public function test_approval_status_is_not_mass_assignable(): void
    {
        [, , $project] = $this->project();
        $line = $project->budgetLines()->create(['work_type' => 'W', 'qty' => 1, 'work_price' => 10]);

        $line->update(['approval_status' => ApprovalStatus::Approved->value, 'work_type' => 'W2']);

        $this->assertSame(ApprovalStatus::Draft, $line->fresh()->approval_status);
        $this->assertSame('W2', $line->fresh()->work_type);
    }

    /** MEDIUM-1: approved + paid procurement cannot be deleted. */
    public function test_locked_procurement_cannot_be_deleted(): void
    {
        [, , $project] = $this->project();
        $item = $project->procurementItems()->create(['name' => 'X', 'price' => 10, 'qty' => 1, 'paid' => true]);
        $item->forceFill(['approval_status' => ApprovalStatus::Approved])->save();

        $this->expectException(\RuntimeException::class);
        $item->delete();
    }

    /** MEDIUM-2: a magic link is single-use. */
    public function test_magic_link_is_single_use(): void
    {
        Notification::fake();
        [, $client] = $this->project();
        $cu = app(InvitationService::class)->invite($client, 'C', 'c@portal.az');

        $token = $cu->fresh()->magic_token;
        $this->assertNotNull($token);

        // Rebuild the signed URL the service just emailed (token is the plaintext
        // inside it; we can only replay through a fresh sign here for the test).
        $plain = 'x';
        $cu->forceFill(['magic_token' => hash('sha256', $plain)])->save();
        $url = URL::temporarySignedRoute('portal.magic-login', now()->addHour(), ['clientUser' => $cu->id, 't' => $plain]);

        $this->get($url)->assertRedirect(route('portal.home'));   // first use works
        $this->post(route('portal.logout'));
        $this->get($url)->assertForbidden();                      // replay blocked
    }

    /** The login-link endpoint is throttled (brute-force defence). */
    public function test_login_link_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('portal.login-link'), ['email' => 'x@test.az'])->assertStatus(302);
        }

        $this->post(route('portal.login-link'), ['email' => 'x@test.az'])->assertStatus(429);
    }

    /** SafeUpload rejects SVG and disguised markup, accepts a real image. */
    public function test_safe_upload_blocks_svg_and_scripts(): void
    {
        $rule = SafeUpload::image();

        $fails = function ($file) use ($rule) {
            $failed = false;
            $rule->validate('file', $file, function () use (&$failed) {
                $failed = true;
            });

            return $failed;
        };

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertTrue($fails($svg), 'SVG must be rejected');

        $disguised = UploadedFile::fake()->createWithContent('image.png', '<svg onload="alert(1)"></svg>');
        $this->assertTrue($fails($disguised), 'PNG that is really SVG must be rejected');

        $png = UploadedFile::fake()->image('real.png', 10, 10);
        $this->assertFalse($fails($png), 'a real PNG must pass');
    }
}
